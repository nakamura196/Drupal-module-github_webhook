---
name: drupal-test-strategy
description: Drupal module test strategy and patterns
user-invokable: false
---

## Drupal Module Test Strategy

### Test Types (priority order)
1. **Unit Tests** (UnitTestCase) - Service logic, token resolution, API response parsing
2. **Kernel Tests** (KernelTestBase) - Config schema validation, service container integration
3. **Functional Tests** (BrowserTestBase) - Form submission, permission checks, routing

### Coverage Targets
- WebhookTriggerService: Unit test all public methods with mocked HTTP client
- StatusController: Unit test JSON response structure
- Forms: Functional test for form rendering and submission
- Permissions: Functional test for access control

### Patterns
- Use `$this->createMock()` for external dependencies (HTTP client, Key module)
- Use prophesized config objects for config reads
- Test both success and failure paths for GitHub API calls
- Namespace: `Drupal\Tests\github_webhook\Unit\` / `Kernel\` / `Functional\`

### What NOT to Test
- Drupal core behavior (form rendering, routing dispatch)
- GitHub API itself (mock all external calls)
