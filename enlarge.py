"""Photo Finder: Enlarge on this PC (see lib/Deblur.php).

    python -I enlarge.py <model.onnx> <photo> <result.jpg> <progress.json> <scale 2|4>

Runs Real-ESRGAN General x4v3 (ONNX Runtime, CPU) over the photo in overlapping 128 px tiles
(the model's fixed input; each comes out 4x larger) and blends the tiles so no seams show. For
2x each tile is reduced (Lanczos) before blending, so memory follows the result's size. As in
deblur.py: the pixels stay in the file's stored orientation, the colour profile is kept, and
progress goes to progress.json: {"state": "running", "done": n, "total": n, "eta": s, ...},
then {"state": "done", "width": w, "height": h, ...} or {"state": "error", "error": "..."}.
"""
import json
import os
import sys
import time

TILE, OVERLAP, MODEL_SCALE = 128, 16, 4


def write_state(path, state):
    state["updated"] = int(time.time())
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(state, f)
    os.replace(tmp, path)


def ramp(np, n, lo_edge, hi_edge, k):
    """Blend weights along one side of a tile: rise over k px except at the photo's edge."""
    w = np.ones(n, dtype=np.float32)
    r = (np.arange(k, dtype=np.float32) + 0.5) / k
    if not lo_edge:
        w[:k] = r
    if not hi_edge:
        w[n - k:] = r[::-1]
    return w


def be_polite():
    """Below-normal priority, as deblur.py: the browser stays responsive while it runs."""
    try:
        import ctypes
        k32 = ctypes.windll.kernel32
        k32.SetPriorityClass(k32.GetCurrentProcess(), 0x00004000)  # BELOW_NORMAL_PRIORITY_CLASS
    except Exception:
        pass


def run(model, src, dst, progress, scale):
    be_polite()
    import numpy as np
    import onnxruntime as ort
    from PIL import Image

    started = int(time.time())
    im = Image.open(src)
    icc = im.info.get("icc_profile")
    orient = im.getexif().get(0x0112, 1)  # EXIF Orientation: the result keeps it, so it shows the right way up
    im = im.convert("RGB")
    a = np.asarray(im, dtype=np.float32) / 255.0
    h, w, _ = a.shape
    ph, pw = max(0, TILE - h), max(0, TILE - w)  # a photo smaller than one tile is padded
    if ph or pw:
        a = np.pad(a, ((0, ph), (0, pw), (0, 0)), mode="symmetric")
    H, W = a.shape[0], a.shape[1]
    step = TILE - OVERLAP
    windows = []
    for y0 in range(0, H, step):
        for x0 in range(0, W, step):
            win = (min(y0, H - TILE), min(x0, W - TILE))
            if win not in windows:
                windows.append(win)
    state = {"state": "running", "done": 0, "total": len(windows), "started": started, "width": w, "height": h}
    write_state(progress, state)

    opts = ort.SessionOptions()
    opts.log_severity_level = 3
    sess = ort.InferenceSession(model, opts, providers=["CPUExecutionProvider"])
    name = sess.get_inputs()[0].name
    k = scale
    n = TILE * k
    acc = np.zeros((H * k, W * k, 3), dtype=np.float32)
    wsum = np.zeros((H * k, W * k, 1), dtype=np.float32)
    t0 = time.time()
    last = 0.0
    for i, (ys, xs) in enumerate(windows):
        tile = np.ascontiguousarray(a[ys:ys + TILE, xs:xs + TILE].transpose(2, 0, 1))[None]
        r = sess.run(None, {name: tile})[0][0].transpose(1, 2, 0)  # TILE*4 square, RGB 0..1
        if k != MODEL_SCALE:
            r = np.stack([np.asarray(Image.fromarray(np.ascontiguousarray(r[:, :, c])).resize((n, n), Image.LANCZOS))
                          for c in range(3)], axis=2)
        wt = np.outer(ramp(np, n, ys == 0, ys + TILE == H, OVERLAP * k),
                      ramp(np, n, xs == 0, xs + TILE == W, OVERLAP * k))[:, :, None]
        acc[ys * k:ys * k + n, xs * k:xs * k + n] += r * wt
        wsum[ys * k:ys * k + n, xs * k:xs * k + n] += wt
        done = i + 1
        now = time.time()
        if now - last >= 0.5 or done == len(windows):  # tiles take ~0.1 s: no need to write each one
            last = now
            per = (now - t0) / done
            state.update(done=done, eta=int(per * (len(windows) - done)))
            write_state(progress, state)

    acc /= np.maximum(wsum, 1e-6)
    del wsum
    out = acc[:h * k, :w * k]
    np.clip(out, 0.0, 1.0, out=out)
    out *= 255.0
    out += 0.5
    res = Image.fromarray(out.astype(np.uint8))
    del acc, out
    kw = {"quality": 95, "subsampling": 0}
    if icc:
        kw["icc_profile"] = icc
    if orient in (2, 3, 4, 5, 6, 7, 8):
        exif = Image.Exif()
        exif[0x0112] = orient
        kw["exif"] = exif
    res.save(dst + ".part.jpg", "JPEG", **kw)
    os.replace(dst + ".part.jpg", dst)
    write_state(progress, {"state": "done", "started": started, "seconds": int(time.time()) - started, "scale": k,
                           "width": res.width, "height": res.height, "total": len(windows), "done": len(windows)})


def main():
    if len(sys.argv) != 6 or sys.argv[5] not in ("2", "4"):
        print(__doc__, file=sys.stderr)
        return 2
    model, src, dst, progress, scale = sys.argv[1:6]
    try:
        run(model, src, dst, progress, int(scale))
    except MemoryError:
        write_state(progress, {"state": "error", "error": "Not enough memory to enlarge a photo this large."})
        return 1
    except Exception as e:  # report anything else to the page instead of dying silently
        write_state(progress, {"state": "error", "error": f"{type(e).__name__}: {e}"})
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
