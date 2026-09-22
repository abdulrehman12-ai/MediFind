# Customer access uses Sanctum personal access tokens

Customers authenticate with bearer tokens issued by Laravel Sanctum. Tokens last 30 days, allow only `customer:access`, and are stored as hashes. Logout and logout-all delete token rows. Account suspension deletes tokens in application code and again in a PostgreSQL trigger. SPA cookie authentication is not enabled. Sanctum stateful domains are an empty list, and `EnsureFrontendRequestsAreStateful` is not registered.
