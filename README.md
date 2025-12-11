# order-management

docker-compose -p order-mgm-dev up -d --build --force-recreate --remove-orphans

docker-compose -p order-mgm-dev ps
docker-compose -p order-mgm-dev down -v --remove-orphans
docker-compose -p order-mgm-dev logs -f app

docker-compose -p order-mgm-dev exec app bash

============================================================

composer create-project laravel/laravel .