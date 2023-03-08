#!/bin/bash

if [ ! -d "storage" ]; then
  mkdir -p storage
fi

if [ ! -f ".env" ]; then
  cp  vendor/craftcms/craft/.env.example.dev ./.env
fi

if ! grep -q "CRAFT_RUN_QUEUE_AUTOMATICALLY=false" .env; then
  echo "" >> .env
  echo "CRAFT_RUN_QUEUE_AUTOMATICALLY=false" >> .env
  echo "" >> .env
fi

if [ ! -f "config/app.php" ]; then
  mkdir -p config
  echo "<?php return [
      'components' => [
          'queue' => [
              'class' => \yii\queue\sync\Queue::class,
              'handle' => true, // if tasks should be executed immediately
          ],
      ],
  ];" > config/app.php
fi

if ! grep -q "ELASTIC_PASSWORD=" .env; then
  echo "" >> .env
  echo "ELASTIC_PASSWORD=secret
        KIBANA_PASSWORD=secret
        STACK_VERSION=8.6.2
        CLUSTER_NAME=docker-cluster
        LICENSE=basic
        ES_PORT=9200
        KIBANA_PORT=5601
        MEM_LIMIT=1073741824" >> .env
  echo "" >> .env
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

./craft plugin/install pest
./craft plugin/install ai
php ./bin/create-default-fs.php > /dev/null 2>&1
