# Attendance API smoke load test

This k6 scenario checks the authenticated dashboard, Personnel, Attendance, and
DTR endpoints with a small ramp to 10 concurrent users. It is intentionally
conservative for the free Render/Aiven testing deployment.

Install [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) and run from
PowerShell without committing credentials:

```powershell
$env:BASE_URL = "https://attendance-tracker-rho-one.vercel.app"
$env:TEST_USERNAME = "your-test-user"
$env:TEST_PASSWORD = Read-Host "Test password"
k6 run .\load-tests\k6-attendance-smoke.js
Remove-Item Env:TEST_PASSWORD
```

Use a non-production test account and test data. A Render free web service can
cold-start after inactivity, so warm `/health/ready` before comparing results.
Do not interpret free-tier latency as production capacity.
