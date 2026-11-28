# Third-party notices

Photo Finder itself is MIT-licensed (see `LICENSE`). Its installer includes the following
components from other authors, each under its own license. Paths are relative to the install
folder (normally `C:\Program Files\Photo Finder`).

| Component | Author | License | Where the license text is |
|---|---|---|---|
| PHP 8.3 (Windows thread-safe build) | The PHP Group | PHP License 3.01 | `php\license.txt` |
| Apache HTTP Server 2.4 (Apache Lounge Win64 build), the app's local web server | The Apache Software Foundation; Windows build by Apache Lounge | Apache License 2.0 | `apache\LICENSE.txt`, `apache\NOTICE.txt` |
| Libraries inside the Apache build (APR, APR-util, APR-iconv, Expat, PCRE2, zlib) | their respective authors | their own licenses (Apache 2.0, MIT, BSD, zlib) | `apache\LICENSE.txt` |
| Libraries inside the PHP build (OpenSSL, libcurl, SQLite, libsodium, GD with libjpeg / libpng / libwebp, zlib and others) | their respective authors | their own licenses, listed by PHP | `php\readme-redist-bins.txt` |
| ExifTool | Phil Harvey | same terms as Perl: Artistic License or GPL | `bin\exiftool_files\LICENSE`, `bin\exiftool_README.txt` |
| Perl runtime inside the ExifTool Windows package (Strawberry Perl components) | their respective authors | their own licenses | `bin\exiftool_files\Licenses_Strawberry_Perl.zip` |
| Mozilla CA certificate bundle (`cacert.pem`, via curl.se) | Mozilla | Mozilla Public License 2.0 | header of `php\cacert.pem` |
| Inno Setup (the installer engine) | Jordan Russell and Martijn Laan | Inno Setup License | https://jrsoftware.org/files/is/license.txt |

**Not included:**

- **Microsoft Visual C++ Redistributable.** PHP and Apache need it. Setup does not bundle it: on a
  PC that lacks it (or has a version older than 14.50), Setup downloads it from Microsoft
  (`https://aka.ms/vc14/vc_redist.x64.exe`),
  checks that it carries a valid Microsoft signature, and runs it. Microsoft's license terms apply.
- **AI models and services.** Photo Finder contains no AI model and no API keys. Anthropic,
  other AI services and local model servers (Ollama, LM Studio) are used through the user's own
  accounts or installations, under their own terms.
- **Fix blur's on-PC runtime.** Downloaded only when Fix blur is first used (into
  `%LOCALAPPDATA%\Photo Finder\deblur`), each file checked against a SHA-256 pinned in
  `lib\Deblur.php`: Python 3.13 embeddable (Python Software Foundation, PSF License, from
  python.org), ONNX Runtime (Microsoft, MIT), NumPy (NumPy developers, BSD 3-Clause) and Pillow
  (Jeffrey A. Clark and contributors, MIT-CMU), from PyPI, and the NAFNet deblurring model
  (megvii-model, MIT; ONNX export from OpenCV's model zoo on Hugging Face). Each package's license
  text is inside its folder there.
- **Enlarge's on-PC model.** Downloaded only when Enlarge is first used (into the same folder),
  checked against a SHA-256 pinned in `lib\Deblur.php`: Real-ESRGAN General x4v3 (Xintao Wang and
  contributors, BSD 3-Clause), as exported to ONNX by Qualcomm AI Hub Models (BSD 3-Clause).
- **People's on-PC face models.** Downloaded only when People is first set up (into the same
  folder, with the runtime above if it is not there yet), checked against SHA-256s pinned in
  `lib\Deblur.php`, from OpenCV's model zoo on Hugging Face: YuNet face detection (Shiqi Yu,
  MIT) and SFace face recognition (Yaoyao Zhong; ONNX conversion by Chengrui Wang; Apache 2.0).
- **Google Gemini** (the optional cloud AI of Fix blur and Enlarge) is used through the user's own
  Google API key, under Google's terms.
