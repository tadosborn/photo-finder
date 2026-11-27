; Photo Finder installer (Inno Setup 6). Built by build.ps1, which stages the app, PHP,
; Apache and ExifTool into StageDir and passes the defines below. The VC++ runtime is
; downloaded from Microsoft at install time, only where it is missing or too old.
; Installs to C:\Program Files\Photo Finder (admin, one UAC prompt). Each user's index, thumbnails
; and settings live in %LOCALAPPDATA%\Photo Finder, so updates and uninstalls never touch them.
; Per-user setup (scheduled tasks, settings) runs as the signed-in user, not the elevated installer.

#ifndef AppVersion
  #error AppVersion must be passed by build.ps1 (/DAppVersion=x.y.z)
#endif
#ifndef StageDir
  #error StageDir must be passed by build.ps1
#endif
#ifndef OutDir
  #define OutDir "."
#endif

[Setup]
; NEVER change AppId: it is how an update finds the copy it replaces.
AppId={{A94339DD-FBD1-4487-A8FA-1BD34B78B35D}
AppName=Photo Finder
AppVersion={#AppVersion}
AppVerName=Photo Finder {#AppVersion}
AppPublisher=InfiNet Computer Solutions, Inc.
AppPublisherURL=https://infinetinc.com
AppSupportURL=https://github.com/tadosborn/photo-finder
AppUpdatesURL=https://github.com/tadosborn/photo-finder/releases
; Version metadata, shown in the installer's Properties > Details.
VersionInfoVersion={#AppVersion}
VersionInfoProductName=Photo Finder
VersionInfoProductVersion={#AppVersion}
VersionInfoCompany=InfiNet Computer Solutions, Inc.
VersionInfoCopyright=Copyright (c) 2026 InfiNet Computer Solutions, Inc. MIT License.
VersionInfoDescription=Photo Finder Setup
DefaultDirName={autopf}\Photo Finder
DefaultGroupName=Photo Finder
DisableProgramGroupPage=yes
UsePreviousAppDir=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
WizardStyle=modern
SetupIconFile={#StageDir}\app\photofinder.ico
UninstallDisplayIcon={app}\photofinder.ico
UninstallDisplayName=Photo Finder
OutputDir={#OutDir}
OutputBaseFilename=PhotoFinderSetup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
; The app's own httpd.exe and php.exe hold files open; [Code] stops them explicitly instead.
CloseApplications=no
RestartApplications=no
SetupLogging=yes
#ifdef SignTool
; Optional Authenticode signing during compile (setup and uninstaller): build.ps1 -SignCommand
; passes the command to ISCC as /Spfsign=... Official releases are not code-signed.
SignTool=pfsign
SignedUninstaller=yes
#endif

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; Flags: unchecked

[InstallDelete]
; Program folders are replaced whole on every install so a file dropped from a release
; does not linger. data\ (index, thumbnails, settings) is never in this list.
Type: filesandordirs; Name: "{app}\lib"
Type: filesandordirs; Name: "{app}\php"
Type: filesandordirs; Name: "{app}\bin"
Type: filesandordirs; Name: "{app}\apache"
Type: filesandordirs; Name: "{app}\installer"
; Same for the program's top-level files (scripts, pages, notices), which are all reinstalled.
Type: files; Name: "{app}\*.php"
Type: files; Name: "{app}\*.ps1"
Type: files; Name: "{app}\*.vbs"
Type: files; Name: "{app}\*.bat"
Type: files; Name: "{app}\*.md"
Type: files; Name: "{app}\*.py"

[Files]
Source: "{#StageDir}\app\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
; Pulled out early by [Code], before anything is copied.
Source: "{#StageDir}\app\uninstall.ps1"; DestDir: "{tmp}"; DestName: "pf-stop.ps1"; Flags: dontcopy

[Icons]
Name: "{autoprograms}\Photo Finder"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\launch.vbs"""; WorkingDir: "{app}"; IconFilename: "{app}\photofinder.ico"; Comment: "Search your photos"
Name: "{autodesktop}\Photo Finder"; Filename: "{sys}\wscript.exe"; Parameters: """{app}\launch.vbs"""; WorkingDir: "{app}"; IconFilename: "{app}\photofinder.ico"; Tasks: desktopicon

[Run]
Filename: "{sys}\wscript.exe"; Parameters: """{app}\launch.vbs"""; Description: "Open Photo Finder now"; Flags: postinstall nowait skipifsilent runasoriginaluser

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\uninstall.ps1"" -Quiet"; Flags: runhidden waituntilterminated; RunOnceId: "PhotoFinderStop"

[Code]
var
  PrivacyPage: TWizardPage;
  UpdateCheckBox: TNewCheckBox;
  FolderPage: TWizardPage;
  FolderList: TNewListBox;
  FolderPageShown: Boolean;

{ ---------- default photo folders: the signed-in user's OneDrive Pictures ---------- }

function StartsWithText(const S, Prefix: String): Boolean;
begin
  Result := (Prefix <> '') and (Length(S) >= Length(Prefix)) and
            (CompareText(Copy(S, 1, Length(Prefix)), Prefix) = 0);
end;

procedure AddFolder(const Dir: String);
var
  I: Integer;
  D: String;
begin
  D := RemoveBackslashUnlessRoot(Trim(Dir));
  if D = '' then Exit;
  for I := 0 to FolderList.Items.Count - 1 do
    if CompareText(FolderList.Items[I], D) = 0 then Exit;
  FolderList.Items.Add(D);
end;

procedure AddDefaultFolders;
var
  Roots: TArrayOfString;
  I: Integer;
  Pics, Root: String;
begin
  { OneDrive sets these for the signed-in user: work/school, personal, and whichever is primary. }
  SetArrayLength(Roots, 3);
  Roots[0] := GetEnv('OneDriveCommercial');
  Roots[1] := GetEnv('OneDriveConsumer');
  Roots[2] := GetEnv('OneDrive');
  { The Pictures known folder (CSIDL_MYPICTURES). When OneDrive backs it up ("folder
    backup"), Windows already points this inside OneDrive. }
  Pics := RemoveBackslashUnlessRoot(GetShellFolderByCSIDL($27, False));
  if Pics = '' then Pics := ExpandConstant('{%USERPROFILE}') + '\Pictures';
  for I := 0 to GetArrayLength(Roots) - 1 do
  begin
    Root := RemoveBackslashUnlessRoot(Roots[I]);
    if (Root = '') or not DirExists(Root) then Continue;
    if StartsWithText(Pics, Root + '\') then
      AddFolder(Pics)
    else if DirExists(Root + '\Pictures') then
      AddFolder(Root + '\Pictures');
  end;
  if FolderList.Items.Count = 0 then
    AddFolder(Pics);
end;

procedure AddButtonClick(Sender: TObject);
var
  Dir: String;
begin
  Dir := '';
  if FolderList.ItemIndex >= 0 then Dir := FolderList.Items[FolderList.ItemIndex];
  if BrowseForFolder('Choose a folder of photos to add:', Dir, False) then
    AddFolder(Dir);
end;

procedure RemoveButtonClick(Sender: TObject);
var
  I: Integer;
begin
  I := FolderList.ItemIndex;
  if I < 0 then Exit;
  FolderList.Items.Delete(I);
  if FolderList.Items.Count > 0 then
  begin
    if I >= FolderList.Items.Count then I := FolderList.Items.Count - 1;
    FolderList.ItemIndex := I;
  end;
end;

procedure DefaultButtonClick(Sender: TObject);
begin
  FolderList.Items.Clear;
  AddDefaultFolders;
end;

procedure InitializeWizard;
var
  Intro, Note: TNewStaticText;
  PrivacyText: TNewMemo;
  AddBtn, RemoveBtn, DefaultBtn: TNewButton;
begin
  FolderPage := CreateCustomPage(wpSelectDir, 'Photo folders',
    'Choose the folders Photo Finder should search. Subfolders are included.');

  Intro := TNewStaticText.Create(FolderPage);
  Intro.Parent := FolderPage.Surface;
  Intro.AutoSize := False;
  Intro.WordWrap := True;
  Intro.Width := FolderPage.SurfaceWidth;
  Intro.Height := ScaleY(30);
  Intro.Caption := 'Your OneDrive Pictures folder is selected to start with. Add any other folders of photos, ' +
                   'or remove ones you do not want searched. You can change this later in Photo Finder''s Settings.';

  AddBtn := TNewButton.Create(FolderPage);
  AddBtn.Parent := FolderPage.Surface;
  AddBtn.Caption := '&Add folder...';
  AddBtn.Width := ScaleX(110);
  AddBtn.Height := ScaleY(23);
  AddBtn.Left := FolderPage.SurfaceWidth - AddBtn.Width;
  AddBtn.Top := Intro.Top + Intro.Height + ScaleY(8);
  AddBtn.OnClick := @AddButtonClick;

  RemoveBtn := TNewButton.Create(FolderPage);
  RemoveBtn.Parent := FolderPage.Surface;
  RemoveBtn.Caption := '&Remove';
  RemoveBtn.Width := AddBtn.Width;
  RemoveBtn.Height := AddBtn.Height;
  RemoveBtn.Left := AddBtn.Left;
  RemoveBtn.Top := AddBtn.Top + AddBtn.Height + ScaleY(6);
  RemoveBtn.OnClick := @RemoveButtonClick;

  DefaultBtn := TNewButton.Create(FolderPage);
  DefaultBtn.Parent := FolderPage.Surface;
  DefaultBtn.Caption := 'Re&set to default';
  DefaultBtn.Width := AddBtn.Width;
  DefaultBtn.Height := AddBtn.Height;
  DefaultBtn.Left := AddBtn.Left;
  DefaultBtn.Top := RemoveBtn.Top + RemoveBtn.Height + ScaleY(6);
  DefaultBtn.OnClick := @DefaultButtonClick;

  FolderList := TNewListBox.Create(FolderPage);
  FolderList.Parent := FolderPage.Surface;
  FolderList.Left := 0;
  FolderList.Top := AddBtn.Top;
  FolderList.Width := AddBtn.Left - ScaleX(8);
  FolderList.Height := ScaleY(130);

  Note := TNewStaticText.Create(FolderPage);
  Note.Parent := FolderPage.Surface;
  Note.AutoSize := False;
  Note.WordWrap := True;
  Note.Top := FolderList.Top + FolderList.Height + ScaleY(8);
  Note.Width := FolderPage.SurfaceWidth;
  Note.Height := ScaleY(44);
  Note.Caption := 'Photos kept online-only in OneDrive are downloaded to this PC as they are indexed. ' +
                  'Photo Finder only reads your photos unless you choose "Embed metadata" in the app.';

  AddDefaultFolders;
  if FolderList.Items.Count > 0 then FolderList.ItemIndex := 0;

  { Privacy: shown at install time, as the code signing policy requires. }
  PrivacyPage := CreateCustomPage(FolderPage.ID, 'Privacy',
    'What Photo Finder sends over the internet, and the choices you have.');
  PrivacyText := TNewMemo.Create(PrivacyPage);
  PrivacyText.Parent := PrivacyPage.Surface;
  PrivacyText.ReadOnly := True;
  PrivacyText.ScrollBars := ssVertical;
  PrivacyText.Width := PrivacyPage.SurfaceWidth;
  PrivacyText.Height := ScaleY(150);
  PrivacyText.Lines.Text :=
    'Photo descriptions: to make your photos searchable, an AI model describes each photo you index. ' +
    'The default is a free model running on this PC (photos never leave it); Settings helps you set it up. ' +
    'You can instead choose Anthropic''s Claude or another AI service with your own API key (each photo is ' +
    'then sent to that service, and its privacy policy applies). Nothing is sent to a cloud service unless ' +
    'you choose one, and you choose which folders are indexed.' + #13#10#13#10 +
    'Fix blur: runs on this PC by default (a one-time download of about 133 MB when first used; photos never leave the PC). ' +
    'Its optional "Try cloud AI" button sends that one photo to Google Gemini with your own Google key.' + #13#10#13#10 +
    'Update checks: when it opens, Photo Finder can ask GitHub (github.com) whether a newer version has been released. ' +
    'Only the version number is involved; nothing about you or your photos is sent. You can turn this ' +
    'off below or later in Settings.' + #13#10#13#10 +
    'Everything else (the search index, thumbnails and settings) stays on this PC. ' +
    'Full statement: https://github.com/tadosborn/photo-finder/blob/main/PRIVACY.md';
  UpdateCheckBox := TNewCheckBox.Create(PrivacyPage);
  UpdateCheckBox.Parent := PrivacyPage.Surface;
  UpdateCheckBox.Top := PrivacyText.Top + PrivacyText.Height + ScaleY(10);
  UpdateCheckBox.Width := PrivacyPage.SurfaceWidth;
  UpdateCheckBox.Height := ScaleY(20);
  UpdateCheckBox.Caption := '&Check for updates automatically when Photo Finder opens';
  UpdateCheckBox.Checked := True;
end;

function ExistingSettings: Boolean;
begin
  Result := FileExists(ExpandConstant('{localappdata}\Photo Finder\settings.json'));
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  { An existing install keeps the folders it has; they are edited in the app's Settings. }
  Result := ((PageID = FolderPage.ID) or (PageID = PrivacyPage.ID)) and ExistingSettings;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = FolderPage.ID then FolderPageShown := True;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (CurPageID = FolderPage.ID) and (FolderList.Items.Count = 0) then
  begin
    MsgBox('Add at least one folder of photos. You can change the list later in Photo Finder''s Settings.', mbError, MB_OK);
    Result := False;
  end;
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo,
  MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
var
  I: Integer;
begin
  Result := MemoDirInfo + NewLine + NewLine;
  if FolderPageShown then
  begin
    Result := Result + 'Photo folders:' + NewLine;
    for I := 0 to FolderList.Items.Count - 1 do
      Result := Result + Space + FolderList.Items[I] + NewLine;
  end else
    Result := Result + 'Photo folders:' + NewLine + Space + 'kept as they are (change them in Settings)' + NewLine;
  if FolderPageShown then
  begin
    if UpdateCheckBox.Checked then Result := Result + NewLine + 'Automatic update checks: on' + NewLine
    else Result := Result + NewLine + 'Automatic update checks: off' + NewLine;
  end;
  if MemoTasksInfo <> '' then Result := Result + NewLine + MemoTasksInfo;
end;

{ ---------- VC++ runtime (PHP and Apache need it; most PCs already have it) ----------
  The bundled Apache is built with Visual Studio 2026 (MSVC 14.50), so the runtime must be at
  least 14.50; an older 14.x runtime is updated in place. }

function VCRedistNeeded: Boolean;
var
  Installed, Major, Minor: Cardinal;
begin
  Result := True;
  if RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Installed', Installed) and
     RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Major', Major) and
     RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Minor', Minor) then
    Result := not ((Installed = 1) and (Major = 14) and (Minor >= 50));
end;

{ Valid Authenticode signature by Microsoft Corporation (checked with Windows PowerShell). The module is
  loaded from Windows PowerShell's own folder: if Setup was started from PowerShell 7, the inherited
  PSModulePath would otherwise make 5.1 try (and fail) to load 7's copy, and every file would look unsigned. }
function MicrosoftSigned(const FileName: String): Boolean;
var
  Code: Integer;
  PsPath: String;
begin
  PsPath := FileName;
  StringChangeEx(PsPath, '''', '''''', True);   { quote for a PowerShell single-quoted string }
  Result := Exec('powershell.exe',
    '-NoProfile -ExecutionPolicy Bypass -Command "Import-Module (Join-Path $PSHOME ''Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1''); ' +
    '$s = Get-AuthenticodeSignature -LiteralPath ''' + PsPath + '''; ' +
    'if ($s.Status -eq ''Valid'' -and $s.SignerCertificate.Subject -match ''O=Microsoft Corporation'') { exit 0 } else { exit 1 }"',
    '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code = 0);
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
  VCFile: String;
begin
  Result := '';
  { 1. Stop this copy's server and worker so their files can be replaced. }
  if FileExists(ExpandConstant('{app}\worker.php')) then
  begin
    ExtractTemporaryFile('pf-stop.ps1');
    Exec('powershell.exe', '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\pf-stop.ps1') +
         '" -Quiet -StopOnly -AppDir "' + ExpandConstant('{app}') + '"', '', SW_HIDE, ewWaitUntilTerminated, Code);
    Log('Stopped running Photo Finder (exit ' + IntToStr(Code) + ')');
  end;

  { 2. Microsoft VC++ runtime, only on a PC that lacks it (Setup is already elevated). It is not
       bundled: it is downloaded from Microsoft and run only if Microsoft's signature verifies. }
  if VCRedistNeeded or (ExpandConstant('{param:TESTVCDOWNLOAD|0}') = '1') then
  begin
    WizardForm.PreparingLabel.Caption := 'Downloading the Microsoft Visual C++ Runtime from Microsoft...';
    try
      { Microsoft's link for the current Visual C++ 2017-2026 runtime. }
      DownloadTemporaryFile('https://aka.ms/vc14/vc_redist.x64.exe', 'vc_redist.x64.exe', '', nil);
    except
      Result := 'Photo Finder needs the Microsoft Visual C++ Runtime, and it could not be downloaded from Microsoft (' +
                GetExceptionMessage + '). Check the internet connection, or install "Visual C++ Redistributable x64" ' +
                'from Microsoft, then run Setup again.';
      Exit;
    end;
    VCFile := ExpandConstant('{tmp}\vc_redist.x64.exe');
    if not MicrosoftSigned(VCFile) then
    begin
      Result := 'The downloaded Microsoft Visual C++ Runtime does not carry a valid Microsoft signature, so Setup did not run it. ' +
                'Install "Visual C++ Redistributable x64" from Microsoft, then run Setup again.';
      Exit;
    end;
    Log('VC++ runtime downloaded and Microsoft signature verified');
    WizardForm.PreparingLabel.Caption := 'Installing the Microsoft Visual C++ Runtime...';
    if not Exec(VCFile, '/install /quiet /norestart', '',
                SW_SHOW, ewWaitUntilTerminated, Code) then
      Result := 'Photo Finder needs the Microsoft Visual C++ Runtime, and its installer could not be started. ' +
                'Install "Visual C++ Redistributable x64" from Microsoft, then run Setup again.'
    else if (Code <> 0) and (Code <> 1638) and (Code <> 3010) then
      Result := 'The Microsoft Visual C++ Runtime installer failed (code ' + IntToStr(Code) + ').';
    { 1638 = a newer version is already installed; 3010 = installed, restart pending. }
  end;
end;

{ ---------- after the files are in place: settings + scheduled tasks ---------- }

procedure CurStepChanged(CurStep: TSetupStep);
var
  Dirs: TArrayOfString;
  I, Code: Integer;
  Params, DirsFile, LogFile: String;
begin
  if CurStep <> ssPostInstall then Exit;
  DirsFile := '';

  Params :='-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\install.ps1') + '" -Quiet';
  { A fresh install writes the chosen (or, silently, the default) folders; an update leaves them alone. }
  if FolderPageShown or not ExistingSettings then
  begin
    SetArrayLength(Dirs, FolderList.Items.Count);
    for I := 0 to FolderList.Items.Count - 1 do Dirs[I] := FolderList.Items[I];
    (* Written to the program folder, not Setup's temp folder: install.ps1 runs as the
       signed-in user, who may not be the administrator that temp folder belongs to.
       Deleted right after. *)
    DirsFile := ExpandConstant('{app}\photo-dirs.txt');
    SaveStringsToUTF8File(DirsFile, Dirs, False);
    Params := Params + ' -PhotoDirsFile "' + DirsFile + '"';
    if UpdateCheckBox.Checked then Params := Params + ' -UpdateCheck 1' else Params := Params + ' -UpdateCheck 0';
  end;
  if ExpandConstant('{param:SKIPTASKS|0}') = '1' then Params := Params + ' -SkipTasks';

  WizardForm.StatusLabel.Caption := 'Setting up Photo Finder...';
  { Scheduled tasks and settings belong to the user who ran Setup, not the elevated installer. }
  ExecAsOriginalUser('powershell.exe', Params, ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, Code);
  Log('install.ps1 exit ' + IntToStr(Code));
  if DirsFile <> '' then DeleteFile(DirsFile);
  if Code <> 0 then
  begin
    LogFile := '%LOCALAPPDATA%\Photo Finder\install.log';
    SuppressibleMsgBox('Photo Finder was copied, but finishing setup failed (code ' + IntToStr(Code) + ').' + #13#10 +
      'Details are in ' + LogFile + #13#10#13#10 +
      'You can retry later by running install.bat in ' + ExpandConstant('{app}') + '.', mbError, MB_OK, IDOK);
  end;
end;

{ ---------- uninstall: offer to keep the index ---------- }

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  DataDir: String;
begin
  if CurUninstallStep <> usPostUninstall then Exit;
  DataDir := ExpandConstant('{localappdata}\Photo Finder');
  if not DirExists(DataDir) then Exit;
  if UninstallSilent then Exit;
  if MsgBox('Also delete your Photo Finder search index, thumbnails and settings?' + #13#10 +
            '(' + DataDir + ')' + #13#10#13#10 +
            'Your photos are not affected either way. Keep the index if you plan to reinstall: ' +
            'indexing a large library again takes a long time.', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES then
    DelTree(DataDir, True, True, True);
end;
