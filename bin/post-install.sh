#!/bin/bash

if [ ! -d "storage" ]; then
  mkdir -p storage
fi

if [ ! -f ".env" ]; then
  cp  vendor/craftcms/craft/.env.example.dev ./.env
fi

if [ ! -d "web" ]; then
  cp -r vendor/craftcms/craft/web ./
fi

if [ ! -f "craft" ]; then
  cp  vendor/craftcms/craft/craft ./
  chmod +x ./craft
fi

if [ ! -f "bootstrap.php" ]; then
  cp  vendor/craftcms/craft/bootstrap.php ./
fi
