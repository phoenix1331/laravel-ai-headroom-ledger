# Replays real `headroom-ledger:report` output captured from the workbench demo
# data (DemoLedgerSeeder) into docs/vhs/report.ansi. Run from the repo root:
#   vhs docs/vhs/report.tape

Output docs/headroom-ledger-report.gif

Set Shell "bash"
Set Theme "TokyoNight"
Set Width 1120
Set Height 680
Set FontSize 14
Set Padding 24
Set TypingSpeed 45ms

Hide
Type "export PS1='$ '; php() { cat docs/vhs/report.ansi; }; clear"
Enter
Show

Sleep 500ms
Type "php artisan headroom-ledger:report --since=7d --by=agent"
Sleep 600ms
Enter
Sleep 8s
