' Start-menu / desktop launcher: makes sure the web server is up, then opens the app.
Dim shell, fso, scriptDir, url, i
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
url = "http://127.0.0.1:8765/"

Function ServerUp()
    On Error Resume Next
    Dim http
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    http.setTimeouts 1000, 1000, 2000, 2000
    http.Open "GET", url & "api.php?action=version", False
    http.Send
    ServerUp = (Err.Number = 0 And http.Status = 200)
    On Error GoTo 0
End Function

If Not ServerUp() Then
    ' Prefer the scheduled task (one server per user, survives this script); fall back to a direct start.
    If shell.Run("schtasks.exe /run /tn PhotoFinderServer", 0, True) <> 0 Then
        shell.Run "wscript.exe """ & scriptDir & "\silent-server.vbs""", 0, False
    End If
    For i = 1 To 40
        WScript.Sleep 250
        If ServerUp() Then Exit For
    Next
End If
shell.Run url
