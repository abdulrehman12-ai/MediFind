# MediFind API agent instructions

Run PHP, Composer, Artisan, Pint, PHPStan, and PHPUnit only inside the project Docker images. Do not install PHP, Composer, PostgreSQL, Redis, or Laravel on the Windows host.

- Application image: `infra/php/Dockerfile` target `runtime` (`medifind-app:phase1a-localdev`)
- Tests: `docker compose --project-name medifind --env-file infra/local.secrets --profile test -f infra/docker-compose.yml run --rm phpunit`
- The runtime image does not contain Composer, Git, or build tools
- Do not install Laravel Boost
- Do not edit merged Phase 1A migrations
- Tests use `medifind_testing`, `medifind_test`, and `redis_test` only
- Do not point tests at the `medifind` development database or development Redis
- Do not commit, push, or open a pull request unless the task explicitly asks for it
