<?php
namespace Tests\Feature;

use App\Support\LivewireActionAudit;
use Tests\TestCase;

/**
 * Same real gap BackupAndAbuseTest's two hand-written checks already caught
 * once each (OtpLogin's SMS cap, EmailLogin's login throttle): a Livewire
 * action lands on /livewire/update, so no route-level `throttle:` middleware
 * ever sees it. This generalizes that into something that catches the NEXT
 * one automatically — a new action added to either component without an
 * entry in LivewireActionAudit::CATALOGUE fails test one below; a costly or
 * security-sensitive action with no real protection in its own source fails
 * test two, even if someone marks it "protected" without actually wiring
 * anything.
 */
class LivewireActionAuditTest extends TestCase
{
    /** @return class-string[] every class in app/Livewire that extends Livewire\Component. */
    private function components(): array
    {
        $classes = [];
        foreach (glob(app_path('Livewire/*.php')) as $file) {
            $class = 'App\\Livewire\\' . basename($file, '.php');
            if (is_subclass_of($class, \Livewire\Component::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        return $classes;
    }

    /** @return string[] public action method names declared directly on $class (not inherited, not render()). */
    private function actionMethods(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) continue;
            if ($method->getName() === 'render') continue;
            if (str_starts_with($method->getName(), '__')) continue;
            $methods[] = $method->getName();
        }
        sort($methods);
        return $methods;
    }

    public function test_every_livewire_action_is_classified_in_the_catalogue(): void
    {
        foreach ($this->components() as $class) {
            $discovered = $this->actionMethods($class);
            $declared = array_keys(LivewireActionAudit::CATALOGUE[$class] ?? []);
            sort($declared);

            $this->assertSame($discovered, $declared,
                "{$class}'s public actions and LivewireActionAudit::CATALOGUE have drifted apart — classify the new or removed action.");
        }
    }

    public function test_every_costly_or_security_sensitive_action_names_real_protection(): void
    {
        foreach (LivewireActionAudit::CATALOGUE as $class => $actions) {
            $reflection = new \ReflectionClass($class);
            $source = file_get_contents($reflection->getFileName());

            foreach ($actions as $name => $def) {
                if (!$def['costs'] && !$def['security']) continue;

                if ($def['protected_by'] === null) {
                    $this->assertNotEmpty($def['unprotected_reason'] ?? null,
                        "{$class}::{$name} costs money or is security-sensitive, has no protection, and no documented reason why that's safe.");
                    continue;
                }

                $this->assertStringContainsString($def['protected_by'], $source,
                    "{$class}::{$name} claims protection by {$def['protected_by']} but that string is not actually in the file — the claim is stale or was never wired up.");
            }
        }
    }

    public function test_no_catalogue_entry_is_missing_its_class(): void
    {
        foreach (array_keys(LivewireActionAudit::CATALOGUE) as $class) {
            $this->assertTrue(class_exists($class), "{$class} is in the catalogue but no longer exists — remove its entry.");
            $this->assertTrue(is_subclass_of($class, \Livewire\Component::class), "{$class} is in the catalogue but is not a Livewire component.");
        }
    }
}
