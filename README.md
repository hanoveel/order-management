# Project Setup (Local Development)

This project is a **Laravel** application (Laravel v12) using:
- **MySQL** for the database
- **Database** queue connection (jobs stored in the database)
- **Vite** for front-end assets
- **npm** for Node.js package management

## Prerequisites

Choose one of the following environments:

### Instructions
Install:
- Docker Desktop (Docker Engine + Docker Compose)
- Copy .env.example to .env
- run `docker-compose up -d --build --force-recreate --remove-orphans`
- after the containers are up, run `docker-compose exec app bash`
- run `composer install`
- run `php artisan migrate`
- the Application will be available at http://localhost

No local PHP/MySQL required.


## Payments Gateways
To add payment gateways:
- Create a Class extending `App\PaymentGateway\Contract\IPaymentGateway` inside `App\PaymentGateway`
- use Create Gateway API to add the gateway giving the class name in the request body.
- Add keys and secrets in the config field in the request body.
- After saving the gateway will be available in the payment gateways list, and can be used by any payment.
