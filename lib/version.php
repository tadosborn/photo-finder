<?php
declare(strict_types=1);

// The one place Photo Finder's version lives. build.ps1 reads it to stamp the installer,
// so a release is made by bumping this.
const PF_VERSION = '1.0.2';

// Where installed copies look for a newer build (Settings > Updates can turn the automatic
// check off). Same XML format as a Joomla update feed.
const PF_UPDATE_FEED = 'https://infinetinc.com/updates/photofinder_update.xml';

// Ed25519 public keys allowed to sign releases (base64). The feed's <signature> must verify
// against one of them over "photofinder\n<version>\n<sha256>\n<file name>", or the update is
// refused. The matching secret key is kept offline by the maintainer and never touches the
// update server, so a compromised server can withhold updates but not push one.
// To rotate: add the new key here, ship one release signed with the OLD key, then sign with
// the new one and drop the old.
const PF_UPDATE_KEYS = [
    '/CwoqGN+TmWyKe9zUfHJSXg52SPiCDjTRTwurDyXAj0=',
];
