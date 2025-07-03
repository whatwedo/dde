# This is a VHS tape. You can find out more about VHS here: https://github.com/charmbracelet/vhs
# You need to map dde into you PATH. You can do this by creating a shell file in `/usr/local/bin/dde` which links to your dde installation like so: `$HOME/dde/dde.sh $@`

Output ../assets/gif/demo.gif

Require echo
Require dde

Set WindowBar "Colorful"
Set WindowBarSize 40
Set BorderRadius 8

Set Shell "bash"
Set FontSize 32
Set Width 1600
Set Height 800

Type "dde up"
Enter
Sleep 1.5s
Type "dde shell"
Enter
Sleep 1.5s
Ctrl+D
Sleep 1.5s
Type "dde stop"
Enter
Type "echo 'Dev-Environment successfully started and stopped 🤓 '"
Sleep 500ms
Enter

Sleep 7s
