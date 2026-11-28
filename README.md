# Photo Finder

Search your own photo library by what is *in* the photos: "dog at the beach", "blue car",
"christmas tree", "receipt from the hardware store". Photo Finder runs on your Windows PC,
has an AI model of your choice describe each photo once, and keeps a local full-text index
you can search instantly.

- **Your choice of AI.** Anthropic Claude or another OpenAI-compatible service (OpenAI,
  Google Gemini, OpenRouter...) with your own API key, or **a local model on your own PC**
  (Ollama or LM Studio) so photos never leave it. Settings shows costs and requirements for
  each, and a **Test** button tries your choice on a sample picture.
- **Your folders.** Setup starts with your OneDrive Pictures folder; add or remove folders any
  time. Subfolders are included; folders named `Screenshots` are skipped by default.
- **Searchable descriptions.** Each photo gets a description, tags and any text visible in it
  (signs, documents), plus GPS location, capture date and face names from the photo's own
  metadata (e.g. from digiKam). Switch AI models any time; Photo Finder remembers which model
  described each photo and can re-describe the rest.
- **Embed metadata.** Optionally write the descriptions and tags into the photo files (XMP /
  IPTC / EXIF) so Windows Explorer, Lightroom, Bridge and others can search them too.
- **Clean up.** Finds blurry, badly exposed, duplicate and small photos for you to review; the
  ones you pick go to the Recycle Bin, or can be fixed in a batch on your PC (remove blur,
  correct colour, enlarge: each fix, and all of them together, saved as copies and reviewed side
  by side on one page). **Queue** shows what is in progress.
- **Fix blur and Enlarge.** Makes a sharper version of a blurred photo, or a larger one of a small
  photo, with AI models that run on your PC (free; downloaded once, about 137 MB; Fix blur is best
  on camera shake), or optionally with Google Gemini and your own key (redraws the photo; a few
  cents each), or the two in turn. Batches offer the same three ways under **Run**, and can let AI (Claude) pick each photo's best version and use it automatically (the original is always kept). Every version
  is saved as a copy next to the original, and **Compare** shows them as large as the window
  allows (click one to see it alone and flip through them with the arrow keys). You can put the
  best one in the original's place; the original is kept beside it, and **Restore the original**
  puts it back at any time. **Show: Fixed photos, with their originals** lists every replaced
  photo beside its original.
- **Enhance and rotate.** Colour, brightness and sharpness fixes from the photo view, and
  lossless quarter turns (R / Shift+R).
- **Share.** From the photo view (S) or a selection in the gallery: Windows' share window (email,
  nearby devices, Phone Link and other apps), Copy, or Download (several photos as a .zip). Full
  size or smaller copies for email; the location is taken out unless you include it.
- **People.** Finds the faces in your photos and recognises the people you name, on your PC only
  (open models, about 38 MB, downloaded once). Names already in your photos' metadata (digiKam,
  Lightroom, Picasa, Windows Photo Gallery) teach it from the start. "Who is this?" groups faces
  that look like one person. Each person's page suggests more of their photos for you to confirm.
  Confirmed names are searchable, and the describing AI uses them ("Tad and Jane at the beach").
  Embed metadata writes them into the files with each face's position. In the photo view, F
  shows a box around each face to name it.
- **Recently updated.** Show only the photos added, described, edited, rotated or fixed in the
  last day, 7 days or 30 days, newest change first.
- **One picture, one place.** The gallery shows each photo once: the versions Fix blur, Enlarge
  and batches save, and the originals kept when you replace one, stay out of it (Clean up's
  "Versions & originals" lists them). Deleting a photo from the gallery takes them with it, to the
  Recycle Bin.
- **Find photos on this PC.** Searches your user folders for pictures outside your photo
  folders and offers to move them in, with undo.
- **Self-contained.** One installer with its own web server (Apache), PHP, SQLite and ExifTool;
  nothing else to install. Runs in the background and opens in your browser at
  `http://127.0.0.1:8765`, reachable from this PC only.
- **Signed updates.** Photo Finder checks this repository's GitHub releases when it opens (you
  can turn this off) and installs an update only after verifying the maintainer's signature.

## Download and install

Download `PhotoFinderSetup-<version>.exe` from the [Releases](../../releases) page and run it.
Windows may warn that it doesn't know the publisher; see
[Windows warnings when installing](#windows-warnings-when-installing). Requirements: Windows 10 or 11 (64-bit). Photos are described by an AI model, one of:

- **Free, on your PC (the default):** [Ollama](https://ollama.com) (free) with a vision model.
  Settings checks your PC, links to Ollama and downloads a recommended model with one click.
  Nothing leaves your PC. A graphics card with 8 GB or more of video memory is recommended;
  without one, expect a minute or more per photo. Descriptions are less detailed than the cloud
  options. [LM Studio](https://lmstudio.ai) works too.
- an [Anthropic API key](https://console.anthropic.com/) (Claude Haiku 5.5 costs roughly
  $3-5 per 10,000 photos at the default image size; an estimate, billed to your account);
- a key for another OpenAI-compatible service with a model that accepts images (cost depends
  on the service).

Set it up in **Settings > How photos are described** after installing.

Program files go to `C:\Program Files\Photo Finder`; your index, thumbnails and settings to
`%LOCALAPPDATA%\Photo Finder`. Open it from the Start menu entry **Photo Finder**.
To uninstall, use Windows **Settings > Apps > Photo Finder**; you are asked whether to keep
the index.

## Privacy

Photos you index are sent to the AI provider you choose (or stay on your PC with the local
option), and the app can check GitHub for updates. Nothing else leaves your PC.
Details and how to turn things off: [PRIVACY.md](PRIVACY.md). Bundled components and their
licenses: [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Windows warnings when installing

The installer is not code-signed, so Windows cannot name its publisher:

- If you downloaded it, Windows may show **Windows protected your PC**. Click **More info**,
  then **Run anyway**.
- The permission prompt says **Unknown publisher**. Click **Yes**.
- Some antivirus programs (Avast, for example) hold an unsigned installer back to analyse it
  first, and each update is a new file. Setup still completes. To stop Avast holding the
  updates Photo Finder installs itself, add an exception (Avast: **Menu > Settings > General >
  Exceptions**) for `%LOCALAPPDATA%\Photo Finder\updates\*`, written out in full, e.g.
  `C:\Users\<you>\AppData\Local\Photo Finder\updates\*`. Photo Finder runs an installer from
  that folder only after verifying its signature.

To confirm your copy is the published one, run `Get-FileHash PhotoFinderSetup-<version>.exe`
in PowerShell and compare the result with the `.sha256` file on the release page. Updates
installed from inside the app don't rely on this check: Photo Finder installs an update only
after verifying the maintainer's Ed25519 signature on it.

Release installers are built from this repository by GitHub Actions
([.github/workflows/build.yml](.github/workflows/build.yml)).

## Building from source

Requires Windows, PowerShell 7 and [Inno Setup 6](https://jrsoftware.org/isinfo.php):

```powershell
pwsh ./build.ps1
```

The build downloads PHP, Apache (Apache Lounge build), ExifTool and the Mozilla CA bundle,
verifies each one against its publisher's checksum, starts the staged web server once to test
it, and writes `dist\PhotoFinderSetup-<version>.exe`. The version comes from `lib/version.php`.

For development, serve the source folder with any Apache + PHP 8.3 (for example XAMPP) and
run the indexer with `worker.bat`. In an installed copy, `start.bat` runs the bundled web server
in a console window for debugging.

## License

[MIT](LICENSE). Bundled components keep their own licenses: PHP (PHP License), Apache HTTP
Server (Apache License 2.0), ExifTool (Artistic/GPL), Mozilla CA bundle (MPL 2.0); see
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
The Microsoft VC++ runtime is not bundled: Setup downloads it from Microsoft when a PC needs it.
