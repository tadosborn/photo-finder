# Photo Finder privacy statement

Photo Finder runs on your own PC. This page lists everything it sends over the internet, and
how to turn each part off. The same summary is shown in the installer.

## Photo descriptions (Anthropic Claude)

To make your photos searchable, Photo Finder asks Anthropic's Claude to describe each photo you
index (what is in it, any visible text, and some search tags). Depending on the backend you
choose in Settings, the photo goes to Claude either:

- through **Claude Code** signed in on this PC (your own Claude account), or
- through the **Anthropic API** with an API key you enter.

With the API backend, each photo is reduced to at most 1568 pixels on the long side before it is
sent (Settings > "Max image dimension sent to API"). With the Claude Code backend, Photo Finder
gives Claude Code the photo's file path and Claude Code reads the file itself. Nothing is sent
until you start indexing, and only photos in the folders you chose are indexed. Anthropic's
privacy policy applies to that processing: <https://www.anthropic.com/legal/privacy>.

To stop sending photos, stop indexing (or remove the folders) in Photo Finder.

## Update checks (infinetinc.com)

When it opens, Photo Finder can ask `https://infinetinc.com/updates/photofinder_update.xml`
whether a newer version exists. The request carries no information about you or your photos;
like any web request it reveals your IP address and the app version (in the `User-Agent`
header) to the server and to Cloudflare, which hosts it. If you click **Install update**, the
installer is downloaded from the same server and verified (Ed25519 signature and SHA-256)
before it runs.

Turn automatic checks off in the installer, or later in **Settings > Updates**. "Check now"
still works when you ask for it.

## Everything else stays on your PC

The search index, thumbnails, settings and logs are stored in `%LOCALAPPDATA%\Photo Finder`.
Photo Finder's web interface listens on `127.0.0.1` only and refuses requests from other
websites. It changes your photo files only when you use **Embed metadata** (writes the
descriptions and tags into the files) or delete photos from the app (they go to the Recycle
Bin).

Uninstalling (Windows Settings > Apps) removes the program and asks whether to delete the
index, thumbnails and settings as well.
