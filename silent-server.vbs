' Runs Photo Finder's web server (PHP's built-in server on 127.0.0.1:8765) in a hidden window.
' Started by the PhotoFinderServer scheduled task at sign-in and by launch.vbs on demand.
' Installed copies only: a source checkout under XAMPP is served by Apache instead.
Dim shell, fso, scriptDir, phpExe, cmd, dataDir, outLog
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
phpExe = scriptDir & "\php\php.exe"
If Not fso.FileExists(phpExe) Then WScript.Quit 1

' Same rule as pf_data_dir() in lib/util.php (installed copy => %LOCALAPPDATA%\Photo Finder).
dataDir = shell.ExpandEnvironmentStrings("%PHOTOFINDER_DATA%")
If dataDir = "%PHOTOFINDER_DATA%" Or dataDir = "" Then
    If fso.FileExists(scriptDir & "\unins000.exe") Then
        dataDir = shell.ExpandEnvironmentStrings("%LOCALAPPDATA%") & "\Photo Finder"
    Else
        dataDir = scriptDir & "\data"
    End If
End If
If Not fso.FolderExists(dataDir) Then fso.CreateFolder(dataDir)
outLog = dataDir & "\server.log"
' -q drops the per-request access lines; startup messages and PHP errors still land in the
' log. Start it fresh once it passes 1 MB so it can never grow without bound.
If fso.FileExists(outLog) Then
    If fso.GetFile(outLog).Size > 1048576 Then fso.DeleteFile outLog, True
End If
cmd = "cmd /c """ & """" & phpExe & """ -q -S 127.0.0.1:8765 -t """ & scriptDir & """ """ & scriptDir & "\router.php"" >> """ & outLog & """ 2>&1"""
shell.CurrentDirectory = scriptDir
shell.Run cmd, 0, False
