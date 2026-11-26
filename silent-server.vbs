' Runs Photo Finder's web server (Apache httpd with PHP, on 127.0.0.1:8765) in a hidden window.
' Started by the PhotoFinderServer scheduled task at sign-in and by launch.vbs on demand.
' Installed copies only: a source checkout under XAMPP is served by XAMPP's Apache instead.
Dim shell, fso, env, scriptDir, httpd, dataDir, serverLog, startLog, appFwd, cmd
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
httpd = scriptDir & "\apache\bin\httpd.exe"
If Not fso.FileExists(httpd) Then WScript.Quit 1

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

' Apache's error log. Start it fresh once it passes 1 MB so it can never grow without bound
' (skipped while a running server still holds it open).
serverLog = dataDir & "\server.log"
On Error Resume Next
If fso.FileExists(serverLog) Then
    If fso.GetFile(serverLog).Size > 1048576 Then fso.DeleteFile serverLog, True
End If
On Error GoTo 0
' Anything Apache prints before its error log is open (a broken config, say) lands here.
startLog = dataDir & "\server-start.log"

' httpd.conf reads these (forward slashes: Apache's path syntax). PHP's own DLLs (OpenSSL,
' libssh2, ...) are found through PATH.
Set env = shell.Environment("Process")
appFwd = Replace(scriptDir, "\", "/")
env("PF_APP") = appFwd
env("PF_DATA") = Replace(dataDir, "\", "/")
env("PF_PORT") = "8765"
env("PATH") = scriptDir & "\php;" & env("PATH")

cmd = "cmd /c """ & """" & httpd & """" & " -d """ & appFwd & "/apache""" & " -f conf/httpd.conf > """ & startLog & """ 2>&1"""
shell.CurrentDirectory = scriptDir
shell.Run cmd, 0, False
