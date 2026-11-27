"""Photo Finder: Fix blur on this PC (see lib/Deblur.php).

    python -I deblur.py <model.onnx> <photo> <result.jpg> <progress.json>

Runs the NAFNet deblurring model (ONNX Runtime, CPU) over the photo in overlapping 512 px tiles
(the model needs at least 384 px each way), blends the tiles so no seams show, and writes the
result as a JPEG. The pixels stay in the file's stored orientation, so the original's EXIF
Orientation (copied on save) stays right; the colour profile is kept. Progress goes to
progress.json after every tile: {"state": "running", "done": n, "total": n, "eta": s, ...},
then {"state": "done"} or {"state": "error", "error": "..."}.
"""
import json
import os
import sys
import time

TILE, OVERLAP, MIN_SIDE = 512, 64, 384


def write_state(path, state):
    state["updated"] = int(time.time())
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(state, f)
    os.replace(tmp, path)


def ramp(np, n, lo_edge, hi_edge):
    """Blend weights along one side of a tile: rise over OVERLAP px except at the photo's edge."""
    w = np.ones(n, dtype=np.float32)
    k = min(OVERLAP, n // 2)
    r = (np.arange(k, dtype=np.float32) + 0.5) / k
    if not lo_edge:
        w[:k] = r
    if not hi_edge:
        w[n - k:] = r[::-1]
    return w


def be_polite():
    """Below-normal priority: the fix uses every core, but only what the rest of the PC leaves
    idle, so the browser and other programs stay responsive while it runs."""
    try:
        import ctypes
        k32 = ctypes.windll.kernel32
        k32.SetPriorityClass(k32.GetCurrentProcess(), 0x00004000)  # BELOW_NORMAL_PRIORITY_CLASS
    except Exception:
        pass


def run(model, src, dst, progress):
    be_polite()
    import numpy as np
    import onnxruntime as ort
    from PIL import Image

    started = int(time.time())
    im = Image.open(src)
    icc = im.info.get("icc_profile")
    im = im.convert("RGB")
    a = np.asarray(im, dtype=np.float32) / 255.0
    h, w, _ = a.shape
    step = TILE - OVERLAP
    windows = []
    for y0 in range(0, h, step):
        for x0 in range(0, w, step):
            ys, ye = max(0, min(y0, h - TILE)), min(h, y0 + TILE)
            xs, xe = max(0, min(x0, w - TILE)), min(w, x0 + TILE)
            if (ys, xs) not in [(q[0], q[2]) for q in windows]:
                windows.append((ys, ye, xs, xe))
    state = {"state": "running", "done": 0, "total": len(windows), "started": started, "width": w, "height": h}
    write_state(progress, state)

    opts = ort.SessionOptions()
    sess = ort.InferenceSession(model, opts, providers=["CPUExecutionProvider"])
    name = sess.get_inputs()[0].name
    acc = np.zeros_like(a)
    wsum = np.zeros((h, w, 1), dtype=np.float32)
    t0 = time.time()
    for i, (ys, ye, xs, xe) in enumerate(windows):
        tile = a[ys:ye, xs:xe]
        th, tw = tile.shape[0], tile.shape[1]
        ph = max(MIN_SIDE - th, 0) + (-max(th, MIN_SIDE)) % 16
        pw = max(MIN_SIDE - tw, 0) + (-max(tw, MIN_SIDE)) % 16
        t = np.pad(tile, ((0, ph), (0, pw), (0, 0)), mode="symmetric")
        r = sess.run(None, {name: t.transpose(2, 0, 1)[None]})[0][0].transpose(1, 2, 0)[:th, :tw]
        wt = np.outer(ramp(np, th, ys == 0, ye == h), ramp(np, tw, xs == 0, xe == w))[:, :, None]
        acc[ys:ye, xs:xe] += r * wt
        wsum[ys:ye, xs:xe] += wt
        done = i + 1
        per = (time.time() - t0) / done
        state.update(done=done, eta=int(per * (len(windows) - done)))
        write_state(progress, state)

    out = acc / np.maximum(wsum, 1e-6)
    res = Image.fromarray((np.clip(out, 0.0, 1.0) * 255.0 + 0.5).astype(np.uint8))
    kw = {"quality": 95, "subsampling": 0}
    if icc:
        kw["icc_profile"] = icc
    res.save(dst + ".part.jpg", "JPEG", **kw)
    os.replace(dst + ".part.jpg", dst)
    write_state(progress, {"state": "done", "started": started, "seconds": int(time.time()) - started,
                           "width": w, "height": h, "total": len(windows), "done": len(windows)})


def main():
    if len(sys.argv) != 5:
        print(__doc__, file=sys.stderr)
        return 2
    model, src, dst, progress = sys.argv[1:5]
    try:
        run(model, src, dst, progress)
    except MemoryError:
        write_state(progress, {"state": "error", "error": "Not enough memory for a photo this large."})
        return 1
    except Exception as e:  # report anything else to the page instead of dying silently
        write_state(progress, {"state": "error", "error": f"{type(e).__name__}: {e}"})
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
