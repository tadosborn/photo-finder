# Photo Finder privacy statement

Photo Finder runs on your own PC. This page lists everything it sends over the internet, and
how to control each part. The same summary is shown in the installer.

## Photo descriptions (the AI provider you choose)

To make your photos searchable, an AI model describes each photo you index (what is in it, any
visible text, and some search tags). You choose the provider in **Settings > How photos are
described**. The default is the free option on your own PC, so no photo is sent anywhere unless
you choose a cloud option; only photos in the folders you chose are indexed.

- **Free, on this PC** (the default; a local model server such as Ollama or LM Studio): photos
  are sent only to that server on your own PC and never leave it. Photo Finder accepts only an
  address on this PC (`localhost` / `127.0.0.1`) for this option. Its **Download** button asks
  Ollama to download a model from Ollama's library; Settings also reads this PC's graphics card
  and memory to say how fast local models will be. That information stays on the PC.
- **Anthropic Claude**, with your own Anthropic API key: each photo you index is sent to
  Anthropic. Anthropic's privacy policy applies: <https://www.anthropic.com/legal/privacy>.
- **Another AI service** (any OpenAI-compatible service, such as OpenAI, Google Gemini or
  OpenRouter), with your own key: each photo you index is sent to that service, and its
  privacy policy applies.

Before a photo is sent, it is reduced to at most 1568 pixels on the long side (Settings >
"Max image dimension sent to the AI"). The **Test with a sample picture** button in Settings
sends a small picture Photo Finder draws itself, not one of your photos.

Your API keys are stored only on your PC, in `%LOCALAPPDATA%\Photo Finder\settings.json`, and
are sent only to the provider they belong to. Photo Finder contains no keys of its own and
never reads keys from anywhere else.

To stop sending photos, stop indexing, remove the folders, or switch to the local option.

## Fix blur

**On this PC** (the default): the photo never leaves your PC. The first time you use Fix blur
(or click **Set up now** in Settings > Fix blur), Photo Finder downloads about 133 MB, once:
Python from `python.org`, three Python packages from PyPI (`files.pythonhosted.org`) and the
deblurring model from Hugging Face (`huggingface.co`). Those are ordinary downloads that send
nothing about you or your photos, and each file is checked against a fixed SHA-256 before use.

**Cloud AI** (optional; only when you click **Try cloud AI** and confirm): the photo (reduced to
at most 3072 pixels on the long side) is sent to Google's Gemini API with your own Google API
key, set in Settings > Fix blur. Google's privacy policy and Gemini API terms apply
(<https://policies.google.com/privacy>). Google marks its results with an invisible SynthID
watermark. Nothing is sent to Google unless you use this button.

Either way, the result is saved only when you click **Save as a copy**, as a new file next to
the original; the original is never changed.

## Update checks (GitHub)

When it opens, Photo Finder can ask GitHub
(`https://api.github.com/repos/tadosborn/photo-finder/releases/latest`) whether a newer version
has been released. The request carries no information about you or your photos; like any web
request it reveals your IP address and the app version (in the `User-Agent` header) to GitHub.
If you click **Install update**, the installer is downloaded from that GitHub release and
verified (the maintainer's Ed25519 signature and SHA-256) before it runs. GitHub's privacy
statement applies to those requests.

Turn automatic checks off in the installer, or later in **Settings > Updates**. "Check now"
still works when you ask for it.

## Setup

If your PC lacks the Microsoft Visual C++ Runtime, Setup downloads it from Microsoft
(`aka.ms`) and installs it. Nothing about you is sent beyond that ordinary download.

## Everything else stays on your PC

The search index, thumbnails, settings and logs are stored in `%LOCALAPPDATA%\Photo Finder`.
Photo Finder's web interface listens on `127.0.0.1` only and refuses requests from other
websites. It changes your photo files only when you ask it to: **Embed metadata** writes the
descriptions and tags into the files; **Enhance** saves a copy (or, if you choose, replaces the
original, which goes to the Recycle Bin first); **Fix blur** saves a copy; **Find photos on this
PC** moves the photos you tick into your photo folder; deleting photos, including from **Clean
up**, moves them to the Recycle Bin.

Uninstalling (Windows Settings > Apps) removes the program and asks whether to delete the
index, thumbnails and settings as well.
