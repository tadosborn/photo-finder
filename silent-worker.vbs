' Launches the Photo Finder worker in a hidden window.
' Started by the PhotoFinderWorker scheduled task so the worker runs without flashing a cmd window.
' Captures stdout and stderr so we can see why it died if it crashes.
Dim shell, fso, scriptDir, phpExe, workerPhp, cmd, dataDir, outLog, errLog
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)

' Same rule as pf_data_dir() in lib/util.php.
Function GetDataDir()
    Dim envDir
    envDir = shell.ExpandEnvironmentStrings("%PHOTOFINDER_DATA%")
    If envDir <> "%PHOTOFINDER_DATA%" And envDir <> "" Then
        GetDataDir = envDir
    ElseIf fso.FileExists(scriptDir & "\php\php.exe") And fso.FileExists(scriptDir & "\unins000.exe") Then
        GetDataDir = shell.ExpandEnvironmentStrings("%LOCALAPPDATA%") & "\Photo Finder"
    Else
        GetDataDir = scriptDir & "\data"
    End If
End Function

' Installed copies carry their own PHP; a source checkout uses XAMPP's.
phpExe = scriptDir & "\php\php.exe"
If Not fso.FileExists(phpExe) Then phpExe = "C:\xampp\php\php.exe"
workerPhp = scriptDir & "\worker.php"
dataDir = GetDataDir()
If Not fso.FolderExists(dataDir) Then fso.CreateFolder(dataDir)
outLog = dataDir & "\worker-stdout.log"
errLog = dataDir & "\worker-stderr.log"
' Run inside cmd.exe so we can redirect stdout/stderr to logs.
' >>file appends instead of overwriting, so we keep history across restarts.
cmd = "cmd /c """ & """" & phpExe & """ """ & workerPhp & """ >> """ & outLog & """ 2>> """ & errLog & """"""
shell.CurrentDirectory = scriptDir
' 0 = hidden window, False = don't wait
shell.Run cmd, 0, False
