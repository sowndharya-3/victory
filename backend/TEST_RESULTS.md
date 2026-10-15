# Backend Foundation — Test Results

Run date: 2026-10-15T06:06:44Z

```
PASS: decode returns correct sub claim
PASS: decode returns correct role claim
PASS: tampered token is rejected
PASS: expired token is rejected

All JWT tests passed.

=== 1. Health check ===
PASS: health returns 200
PASS: health reports ok status
=== 2. Customer registration ===
PASS: register returns 201
PASS: register returns a token
=== 2b. Duplicate registration ===
PASS: duplicate email returns 409
=== 3. Customer login ===
PASS: login returns 200
PASS: login returns a token
=== 4. GET /api/me with customer token ===
PASS: me returns 200
PASS: me returns correct email
=== 5. Admin login ===
PASS: admin login returns 200
PASS: admin login returns a token
=== 6. Protected route with admin token ===
PASS: admin me returns 200
PASS: admin me reports admin role
=== 7. Invalid / expired JWT handling ===
PASS: no token returns 401
PASS: garbage token returns 401
PASS: expired token returns 401
PASS: expired token error code is token_expired
=== 8. Invalid credentials ===
PASS: wrong password returns 401
=== 9. CORS preflight ===
PASS: preflight echoes exact configured origin
PASS: preflight does not use wildcard origin
=== 10. Database connectivity ===
PASS: health check queried a live database (already proven in step 1)

Results: 21 passed, 0 failed
```
