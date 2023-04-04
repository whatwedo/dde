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

Type "cd with-mutagen"
Sleep 500ms
Enter
Sleep 2s

Type "dde up"
Sleep 500ms
Enter
Sleep 6s

Type "dde exec"
Sleep 500ms
Enter
Sleep 4s

Type "echo 'Dev-Environment successfully started 🤓 '"
Sleep 500ms
Enter

Sleep 7s
