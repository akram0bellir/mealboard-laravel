# Limits the Mealboard Apache (deploy\apache-mealboard.conf) to ONE CPU core, for load tests.
param([int]$Core = 0)
$mask = [IntPtr](1 -shl $Core)
Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object { $_.CommandLine -like '*apache-mealboard.conf*' } | ForEach-Object {
  $p = Get-Process -Id $_.ProcessId; $p.ProcessorAffinity = $mask; "httpd $($p.Id) -> core $Core"
}
