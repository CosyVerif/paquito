#!/bin/bash

# ----- DEBIAN -----
# Packaging Paquito
docker build -t base/archlinux:paquito - < arch-packaging-paquito.Dockerfile 
# We have to run a image to create a container (and get the package)
ID=$(docker run -t base/archlinux:paquito /bin/cat /etc/hostname)
ID=${ID::-1} # Remove the last char (return)
docker cp $ID:/paquito/src/paquito-0.2-x86_64/paquito-0.2-1-x86_64.pkg.tar.xz create-HelloWorld/

# Packaging HelloWorld
cd create-HelloWorld/
tar -zcvf arch-tar-HelloWorld.tar.gz Dockerfile paquito-0.2-1-x86_64.pkg.tar.xz src-test*
cd ..
docker build -t base/archlinux:paquito - < create-HelloWorld/arch-tar-HelloWorld.tar.gz
ID=$(docker run -t base/archlinux:paquito /bin/cat /etc/hostname)
ID=${ID::-1} # Remove the last char (return)
docker cp $ID:/hello-world/helloworld-1.2-x86_64/helloworld-1.2-1-x86_64.pkg.tar.xz test-HelloWorld/
docker cp $ID:/hello-world/helloworld-1.2-x86_64-test/helloworld-test-1.2-1-x86_64.pkg.tar.xz test-HelloWorld/

# Test HelloWorld
cd test-HelloWorld/
tar -zcvf arch-tar-Test.tar.gz Dockerfile composer.json helloworld-1.2-1-x86_64.pkg.tar.xz helloworld-test-1.2-1-x86_64.pkg.tar.xz
cd ..
docker build -t base/archlinux:paquito - < test-HelloWorld/arch-tar-Test.tar.gz
