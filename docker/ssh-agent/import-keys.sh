#!/bin/sh

for sshKey in /home/dde/.ssh/*; do
  if grep -q PRIVATE "${sshKey}"; then
    ssh-add ${sshKey};
  fi;
done

ssh-add -l
