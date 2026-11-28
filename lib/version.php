<?php
declare(strict_types=1);

// The one place Photo Finder's version lives. build.ps1 reads it to stamp the installer,
// so a release is made by bumping this.
const PF_VERSION = '1.0.18';

// Where installed copies look for a newer build (Settings > Updates can turn the automatic
// check off): the latest GitHub release of this repository. A release is offered only if it
// carries photofinder-release.json signed with one of the keys below (see lib/Updater.php).
const PF_UPDATE_SOURCE = 'https://api.github.com/repos/tadosborn/photo-finder/releases/latest';

// Ed25519 public keys allowed to sign releases (base64). The manifest's signature must verify
// against one of them over "photofinder\n<version>\n<sha256>\n<file name>", or the update is
// refused. The matching secret key is kept offline by the maintainer and never touches GitHub
// or any server, so whoever could alter a release can withhold updates but not push one.
// To rotate: add the new key here, ship one release signed with the OLD key, then sign with
// the new one and drop the old.
const PF_UPDATE_KEYS = [
    '/CwoqGN+TmWyKe9zUfHJSXg52SPiCDjTRTwurDyXAj0=',
];
