# Third-party notices

Photo Finder itself is MIT-licensed (see `LICENSE`). Its installer includes the following
components from other authors, each under its own license. Paths are relative to the install
folder (normally `C:\Program Files\Photo Finder`).

| Component | Author | License | Where the license text is |
|---|---|---|---|
| PHP 8.3 (Windows NTS build) | The PHP Group | PHP License 3.01 | `php\license.txt` |
| Libraries inside the PHP build (OpenSSL, libcurl, SQLite, libsodium, GD with libjpeg / libpng / libwebp, zlib and others) | their respective authors | their own licenses, listed by PHP | `php\readme-redist-bins.txt` |
| ExifTool | Phil Harvey | same terms as Perl: Artistic License or GPL | `bin\exiftool_files\LICENSE`, `bin\exiftool_README.txt` |
| Perl runtime inside the ExifTool Windows package (Strawberry Perl components) | their respective authors | their own licenses | `bin\exiftool_files\Licenses_Strawberry_Perl.zip` |
| Mozilla CA certificate bundle (`cacert.pem`, via curl.se) | Mozilla | Mozilla Public License 2.0 | header of `php\cacert.pem` |
| Inno Setup (the installer engine) | Jordan Russell and Martijn Laan | Inno Setup License | https://jrsoftware.org/files/is/license.txt |

**Not included:**

- **Microsoft Visual C++ Redistributable.** PHP needs it. Setup does not bundle it: on a PC
  that lacks it, Setup downloads it from Microsoft (`https://aka.ms/vs/17/release/vc_redist.x64.exe`),
  checks that it carries a valid Microsoft signature, and runs it. Microsoft's license terms apply.
- **AI models and services.** Photo Finder contains no AI model and no API keys. Anthropic,
  other AI services and local model servers (Ollama, LM Studio) are used through the user's own
  accounts or installations, under their own terms.
