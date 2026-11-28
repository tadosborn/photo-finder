"""People in Photo Finder: find faces, and compare them, on this PC (lib/People.php drives it).

Runs with the on-PC AI runtime that Fix blur and Enlarge use (embeddable Python, ONNX Runtime,
NumPy, Pillow) and two models from OpenCV's model zoo:
  face_detection_yunet_2023mar.onnx    YuNet, finds faces and five points on each (MIT licence)
  face_recognition_sface_2021dec.onnx  SFace, turns a face into 128 numbers (Apache 2.0)
Two faces of the same person give numbers that point the same way (cosine similarity about
0.36 and up). Nothing leaves this PC.

  python faces.py detect <models> <job.json> <out.jsonl> <thumbs> <state.json>
      job.json: [{"id": ..., "path": ...}, ...]. For each photo, one line of out.jsonl:
      {"id", "faces": [{"x","y","w","h" (0..1 of the upright photo), "score", "px", "emb"
      (base64 float32 x 128, unit length), "thumb"}]}, or {"id", "error"}. Each face's picture
      goes to <thumbs>/<id>-<tag>-<n>.jpg (tag: the job item's, so a new scan never overwrites
      the picture of a face kept from an earlier one).
  python faces.py match <db> <request.json> <out.json>
      "clusters": groups of unnamed faces that look like the same person, largest first.
      "suggest":  unnamed faces that look like person P, best first.
      "who":      the people face F looks most like.
"""
import base64
import json
import os
import sys
import time

DET_SIZE = 640          # YuNet's input (the model is exported for 640 x 640)
SCORE_MIN = 0.80        # face detector confidence
FACE_MIN_PX = 40        # smaller faces give unreliable comparisons: not kept
SAME = 0.363            # SFace: cosine similarity at which two faces are the same person (OpenCV)
TEMPLATE = [[38.2946, 51.6963], [73.5318, 51.5014], [56.0252, 71.7366], [41.5493, 92.3655], [70.7299, 92.2041]]


def be_polite():
    """Idle priority: only spare CPU, so the PC and Photo Finder stay responsive."""
    try:
        import ctypes
        from ctypes import wintypes
        k32 = ctypes.WinDLL("kernel32")
        k32.GetCurrentProcess.restype = wintypes.HANDLE
        k32.SetPriorityClass.argtypes = [wintypes.HANDLE, wintypes.DWORD]
        k32.SetPriorityClass.restype = wintypes.BOOL
        k32.SetPriorityClass(k32.GetCurrentProcess(), 0x00000040)  # IDLE_PRIORITY_CLASS
    except Exception:
        pass


def write_state(path, state):
    """Replace the state file whole; Windows refuses while a reader has it open, so retry."""
    if not path:
        return
    tmp = path + ".py.tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(state, f)
    for _ in range(100):
        try:
            os.replace(tmp, path)
            return
        except PermissionError:
            time.sleep(0.05)


def session(ort, path):
    opts = ort.SessionOptions()
    opts.log_severity_level = 3
    opts.intra_op_num_threads = 2
    return ort.InferenceSession(path, opts, providers=["CPUExecutionProvider"])


def detect_square(np, det, img):
    """Faces in a PIL image of at most DET_SIZE a side, padded to the model's square:
    [(x1, y1, w, h, score, [10 landmark coords])] in that image's pixels."""
    w, h = img.size
    a = np.zeros((DET_SIZE, DET_SIZE, 3), dtype=np.float32)
    a[:h, :w] = np.asarray(img, dtype=np.float32)[:, :, ::-1]   # RGB -> BGR, 0..255
    outs = dict(zip([o.name for o in det.get_outputs()], det.run(None, {det.get_inputs()[0].name: a.transpose(2, 0, 1)[None]})))
    found = []
    for stride in (8, 16, 32):
        cols = DET_SIZE // stride
        cls = np.clip(outs["cls_%d" % stride][0, :, 0], 0, 1)
        obj = np.clip(outs["obj_%d" % stride][0, :, 0], 0, 1)
        score = np.sqrt(cls * obj)
        for i in np.nonzero(score >= SCORE_MIN)[0]:
            r, c = divmod(int(i), cols)
            b = outs["bbox_%d" % stride][0, i]
            k = outs["kps_%d" % stride][0, i]
            cx, cy = (c + b[0]) * stride, (r + b[1]) * stride
            bw, bh = np.exp(b[2]) * stride, np.exp(b[3]) * stride
            pts = []
            for n in range(5):
                pts += [float((k[2 * n] + c) * stride), float((k[2 * n + 1] + r) * stride)]
            found.append((float(cx - bw / 2), float(cy - bh / 2), float(bw), float(bh), float(score[i]), pts))
    return found


def iou(a, b):
    x1, y1 = max(a[0], b[0]), max(a[1], b[1])
    x2, y2 = min(a[0] + a[2], b[0] + b[2]), min(a[1] + a[3], b[1] + b[3])
    inter = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    union = a[2] * a[3] + b[2] * b[3] - inter
    return inter / union if union > 0 else 0.0


def find_faces(np, Image, det, img):
    """All faces in an upright photo, in its pixels: the whole photo at the model's size, plus
    overlapping squares of a larger copy so small faces in group photos are found too."""
    W, H = img.size
    found = []
    s = DET_SIZE / max(W, H)
    small = img.resize((max(1, round(W * s)), max(1, round(H * s))), Image.BILINEAR) if s < 1 else img
    s = small.size[0] / W
    for f in detect_square(np, det, small):
        found.append((f[0] / s, f[1] / s, f[2] / s, f[3] / s, f[4], [v / s for v in f[5]]))
    if max(W, H) > DET_SIZE * 1.4:
        s2 = min(1.0, 2 * DET_SIZE / max(W, H))
        big = img.resize((round(W * s2), round(H * s2)), Image.BILINEAR) if s2 < 1 else img
        bw, bh = big.size

        def origins(n):
            if n <= DET_SIZE:
                return [0]
            o = list(range(0, n - DET_SIZE, DET_SIZE * 3 // 4))
            return o + [n - DET_SIZE]
        for oy in origins(bh):
            for ox in origins(bw):
                tile = big.crop((ox, oy, min(bw, ox + DET_SIZE), min(bh, oy + DET_SIZE)))
                for f in detect_square(np, det, tile):
                    pts = [(v + (ox if j % 2 == 0 else oy)) / s2 for j, v in enumerate(f[5])]
                    found.append(((f[0] + ox) / s2, (f[1] + oy) / s2, f[2] / s2, f[3] / s2, f[4], pts))
    found.sort(key=lambda f: -f[4])
    keep = []
    for f in found:
        if all(iou(f, k) < 0.4 for k in keep):
            keep.append(f)
    return keep


def align(np, Image, img, pts):
    """The face turned and scaled onto SFace's 112 x 112 template by its five points (Umeyama)."""
    src = np.array(pts, dtype=np.float64).reshape(5, 2)
    dst = np.array(TEMPLATE, dtype=np.float64)
    ms, md = src.mean(0), dst.mean(0)
    sc, dc = src - ms, dst - md
    cov = dc.T @ sc / 5
    U, S, Vt = np.linalg.svd(cov)
    d = np.ones(2)
    if np.linalg.det(cov) < 0:
        d[-1] = -1
    R = U @ np.diag(d) @ Vt
    scale = (S * d).sum() / ((sc ** 2).sum() / 5)
    M = np.eye(3)
    M[:2, :2] = scale * R
    M[:2, 2] = md - scale * R @ ms
    inv = np.linalg.inv(M)        # PIL maps each output pixel back into the photo
    return img.transform((112, 112), Image.AFFINE, tuple(inv[:2].flatten()), resample=Image.BILINEAR)


def embed(np, rec, face):
    a = np.asarray(face, dtype=np.float32).transpose(2, 0, 1)[None]   # RGB, 0..255
    v = rec.run(None, {rec.get_inputs()[0].name: a})[0][0].astype(np.float32)
    n = float(np.linalg.norm(v))
    return v / n if n > 0 else v


def face_thumb(Image, img, f, dest):
    x, y, w, h = f[0], f[1], f[2], f[3]
    side = max(w, h) * 1.5
    cx, cy = x + w / 2, y + h / 2
    box = (int(cx - side / 2), int(cy - side / 2), int(cx + side / 2), int(cy + side / 2))
    img.crop(box).resize((128, 128), Image.BILINEAR).save(dest, "JPEG", quality=85)


def detect(models, job, out, thumbs, state_path):
    be_polite()
    import numpy as np
    import onnxruntime as ort
    from PIL import Image, ImageOps
    Image.MAX_IMAGE_PIXELS = None
    det = session(ort, os.path.join(models, "face_detection_yunet_2023mar.onnx"))
    rec = session(ort, os.path.join(models, "face_recognition_sface_2021dec.onnx"))
    with open(job, encoding="utf-8") as f:
        items = json.load(f)
    os.makedirs(thumbs, exist_ok=True)
    state = {"state": "running", "done": 0, "total": len(items), "faces": 0, "started": int(time.time())}
    write_state(state_path, state)
    with open(out, "a", encoding="utf-8") as o:
        for it in items:
            rec_out = {"id": it["id"]}
            try:
                im = Image.open(it["path"])
                if im.format == "JPEG":
                    im.draft("RGB", (2048, 2048))     # decode at a reduced size: much faster
                im = ImageOps.exif_transpose(im).convert("RGB")
                W, H = im.size
                faces = []
                for f in sorted(find_faces(np, Image, det, im), key=lambda f: f[0]):
                    if min(f[2], f[3]) < FACE_MIN_PX:
                        continue
                    n = len(faces)
                    v = embed(np, rec, align(np, Image, im, f[5]))
                    thumb = "%s-%s-%d.jpg" % (it["id"], it.get("tag", "0"), n)
                    face_thumb(Image, im, f, os.path.join(thumbs, thumb))
                    faces.append({
                        "x": round(max(0.0, f[0]) / W, 5), "y": round(max(0.0, f[1]) / H, 5),
                        "w": round(min(f[2], W - max(0.0, f[0])) / W, 5), "h": round(min(f[3], H - max(0.0, f[1])) / H, 5),
                        "score": round(f[4], 4), "px": int(min(f[2], f[3])),
                        "emb": base64.b64encode(v.astype("<f4").tobytes()).decode("ascii"), "thumb": thumb,
                    })
                rec_out["faces"] = faces
                state["faces"] += len(faces)
            except Exception as e:
                rec_out["error"] = str(e)[:300]
            o.write(json.dumps(rec_out) + "\n")
            o.flush()
            state["done"] += 1
            write_state(state_path, state)
    state["state"] = "done"
    write_state(state_path, state)


def load_faces(np, db):
    import sqlite3
    con = sqlite3.connect("file:" + db + "?mode=ro", uri=True, timeout=30)
    rows = con.execute("SELECT f.id, f.image_id, COALESCE(f.person_id, 0), f.emb FROM face f "
                       "JOIN images i ON i.id = f.image_id "
                       "WHERE f.ignored = 0 AND i.status = 'ok' AND f.emb IS NOT NULL").fetchall()
    rejects = con.execute("SELECT face_id, person_id FROM face_reject").fetchall()
    con.close()
    ids = np.array([r[0] for r in rows], dtype=np.int64)
    imgs = [r[1] for r in rows]
    person = np.array([r[2] for r in rows], dtype=np.int64)
    emb = np.frombuffer(b"".join(r[3] for r in rows), dtype="<f4").reshape(-1, 128) if rows else np.zeros((0, 128), np.float32)
    return ids, imgs, person, emb, rejects


def clusters(np, ids, person, emb, req):
    """Unnamed faces in groups that look like one person, built round seeds: the face with the
    most faces at least LINK like it starts a group of those faces, the group is redrawn round its
    average face (every free face at least LINK like that), and the next seed goes on with the faces
    left. Linking faces in chains instead (A like B, B like C) put several children in one group in
    the maintainer's photos; seeds kept each group to one person, at any age."""
    link = float(req.get("link", 0.50))
    k = int(req.get("k", 50))
    u = np.nonzero(person == 0)[0]
    E = emb[u]
    n = len(u)
    if n < 2:
        return []
    k = min(k, n - 1)
    nbr, deg = [], np.zeros(n, dtype=np.int64)
    for s in range(0, n, 1024):
        S = E[s:s + 1024] @ E.T
        for r in range(S.shape[0]):
            S[r, s + r] = -1
        top = np.argpartition(-S, k - 1, axis=1)[:, :k]
        for r in range(S.shape[0]):
            near = [int(j) for j in top[r] if S[r, j] >= link]
            nbr.append(near)
            deg[s + r] = len(near)
    free = np.ones(n, dtype=bool)
    out = []
    for s in np.argsort(-deg, kind="stable"):
        s = int(s)
        if not free[s] or deg[s] == 0:
            continue
        m = [j for j in nbr[s] if free[j]] + [s]
        if len(m) < 2:
            continue
        c = E[m].mean(0)
        c /= np.linalg.norm(c)
        likeness = E @ c
        m = set(np.nonzero(free & (likeness >= link))[0].tolist()) | {s}
        if len(m) < int(req.get("min", 2)):
            continue
        m = sorted(m, key=lambda j: -likeness[j])
        free[m] = False
        out.append([int(ids[u[j]]) for j in m])
    out.sort(key=len, reverse=True)
    return out[: int(req.get("limit", 200))]


def suggest(np, ids, imgs, person, emb, rejects, req):
    """Unnamed faces like person P's confirmed ones: each scored by the mean of its three best
    likenesses to them (one lucky likeness is not enough), best first. Faces in photos where P is
    already named, and faces the user said are not P, are left out."""
    p = int(req["person"])
    mine = np.nonzero(person == p)[0]
    if len(mine) == 0:
        return []
    taken = {imgs[i] for i in mine}
    no = {f for f, q in rejects if q == p}
    cand = [i for i in np.nonzero(person == 0)[0] if imgs[i] not in taken and int(ids[i]) not in no]
    if not cand:
        return []
    S = emb[cand] @ emb[mine].T
    t = min(3, S.shape[1])
    score = np.sort(S, axis=1)[:, -t:].mean(axis=1)
    floor = float(req.get("min", SAME))
    best = {}
    for j in np.argsort(-score):
        if score[j] < floor:
            break
        i = cand[j]
        if imgs[i] in best:          # one face per photo: its likeliest
            continue
        best[imgs[i]] = (int(ids[i]), round(float(score[j]), 4))
    return [{"face": f, "score": s} for f, s in best.values()][: int(req.get("limit", 2000))]


def who(np, ids, person, emb, req):
    """The named people face F looks most like: [{"person", "score"}], best first."""
    f = int(req["face"])
    w = np.nonzero(ids == f)[0]
    if len(w) == 0:
        return []
    v = emb[w[0]]
    out = []
    for p in sorted({int(q) for q in person if q != 0}):
        S = emb[person == p] @ v
        t = min(3, len(S))
        out.append({"person": p, "score": round(float(np.sort(S)[-t:].mean()), 4)})
    out.sort(key=lambda r: -r["score"])
    return [r for r in out if r["score"] >= float(req.get("min", 0.30))][:5]


def match(db, request, out):
    be_polite()
    import numpy as np
    with open(request, encoding="utf-8") as f:
        req = json.load(f)
    ids, imgs, person, emb, rejects = load_faces(np, db)
    mode = req.get("mode")
    if mode == "clusters":
        res = {"clusters": clusters(np, ids, person, emb, req)}
    elif mode == "suggest":
        res = {"suggestions": suggest(np, ids, imgs, person, emb, rejects, req)}
    elif mode == "who":
        res = {"who": who(np, ids, person, emb, req)}
    else:
        raise ValueError("unknown mode")
    with open(out, "w", encoding="utf-8") as f:
        json.dump(res, f)


if __name__ == "__main__":
    if len(sys.argv) >= 7 and sys.argv[1] == "detect":
        detect(*sys.argv[2:7])
    elif len(sys.argv) >= 5 and sys.argv[1] == "match":
        match(*sys.argv[2:5])
    else:
        print(__doc__)
        sys.exit(2)
