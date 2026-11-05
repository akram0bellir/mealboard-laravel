' Runs the Mealboard scheduler once with no window (used by the Windows scheduled task).
Dim fso, root
Set fso = CreateObject("Scripting.FileSystemObject")
root = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName))
CreateObject("WScript.Shell").CurrentDirectory = root
CreateObject("WScript.Shell").Run """C:\xampp\php\php.exe"" """ & root & "\artisan"" schedule:run", 0, True
