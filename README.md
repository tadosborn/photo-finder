# Photo Finder

Search your own photo library by what is *in* the photos: "dog at the beach", "blue car",
"christmas tree", "receipt from the hardware store". Photo Finder runs on your Windows PC,
asks Anthropic's Claude to describe each photo once, and keeps a local full-text index you
can search instantly.

- **Your folders.** Setup starts with your OneDrive Pictures folder; add or remove folders any
  time. Subfolders are included; folders named `Screenshots` are skipped by default.
- **Searchable descriptions.** Each photo gets a description, tags and any text visible in it
  (signs, documents), plus GPS location, capture date and face names from the photo's own
  metadata (e.g. from digiKam).
- **Embed metadata.** Optionally write the descriptions and tags into the photo files (XMP /
  IPTC / EXIF) so Windows Explorer, Lightroom, Bridge and others can search them too.
- **Self-contained.** One installer with its own PHP, SQLite and ExifTool; nothing else to
  install. Runs in the background and opens in your browser at `http://127.0.0.1:8765`.
- **Signed updates.** Photo Finder checks for new versions when it opens (you can turn this
  off) and installs them only after verifying their signature.

## Download and install

Download `PhotoFinderSetup-<version>.exe` from the [Releases](../../releases) page and run it.
Requirements: Windows 10 or 11 (64-bit), and either
[Claude Code](https://claude.com/claude-code) signed in on the PC or an
[Anthropic API key](https://console.anthropic.com/) (enter it in Settings).

Program files go to `C:\Program Files\Photo Finder`; your index, thumbnails and settings to
`%LOCALAPPDATA%\Photo Finder`. Open it from the Start menu entry **Photo Finder**.
To uninstall, use Windows **Settings > Apps > Photo Finder**; you are asked whether to keep
the index.

## Privacy

Photos you index are sent to Anthropic's Claude to be described, and the app can check
infinetinc.com for updates. Nothing else leaves your PC. Details and how to turn things off:
[PRIVACY.md](PRIVACY.md).

## Code signing policy

Free code signing provided by [SignPath.io](https://about.signpath.io/), certificate by
[SignPath Foundation](https://signpath.org/).

- Committers and reviewers: [@tadosborn](https://github.com/tadosborn)
- Approvers: [@tadosborn](https://github.com/tadosborn)
- Privacy policy: [PRIVACY.md](PRIVACY.md)

Release installers are built from this repository by GitHub Actions
([.github/workflows/build.yml](.github/workflows/build.yml)) and signed only after manual
approval of each release.

## Building from source

Requires Windows, PowerShell 7 and [Inno Setup 6](https://jrsoftware.org/isinfo.php):

```powershell
pwsh ./build.ps1
```

The build downloads PHP, ExifTool, the Mozilla CA bundle and the Microsoft VC++ runtime,
verifies each one (checksums from the publishers, Microsoft's signature), and writes
`dist\PhotoFinderSetup-<version>.exe`. The version comes from `lib/version.php`.

For development without installing, `start.bat` serves the app with a local PHP (bundled
`php\` or XAMPP's) and `worker.bat` runs the indexer in a console window.

## License

[MIT](LICENSE). Bundled components keep their own licenses: PHP (PHP License), ExifTool
(Artistic/GPL), Microsoft VC++ runtime (Microsoft redistributable), Mozilla CA bundle (MPL 2.0).
