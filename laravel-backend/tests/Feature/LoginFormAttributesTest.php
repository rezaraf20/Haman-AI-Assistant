<?php
namespace Tests\Feature;

use App\Livewire\{EmailLogin, OtpLogin};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Real reported bug: on the OTP-login "profile" step, typing into one field
 * (first_name, last_name, national_id, email, address) mirrored into the
 * others — every wire:model was already distinct, so the actual cause was
 * that none of the five inputs carried an id/name/wire:key at all: five
 * structurally-identical sibling <input> tags give Livewire 3's Alpine-morph
 * DOM diffing nothing to anchor node identity on when the step transition
 * (1 input -> 5 inputs) reconciles the tree, and it can rebind the wrong
 * live DOM node's value to the wrong model. Same latent bug existed in
 * EmailLogin's two password fields (register mode).
 *
 * This can't fully reproduce the browser-side symptom (that needs a real
 * DOM/Alpine morph, which no PHP test can drive), but it does verify the
 * actual fix: every input Livewire could ever confuse now has a unique id,
 * a name, and a wire:key on its wrapper — the three things that give the
 * morph algorithm something to anchor on.
 */
class LoginFormAttributesTest extends TestCase
{
    use RefreshDatabase;

    /** @return string[] every id="..." found on an <input> or <textarea> tag, in document order. */
    private function extractFieldIds(string $html): array
    {
        preg_match_all('/<(?:input|textarea)\b[^>]*\bid="([^"]+)"[^>]*>/i', $html, $matches);
        return $matches[1];
    }

    /** @return string[] every name="..." found on an <input> or <textarea> tag, in document order. */
    private function extractFieldNames(string $html): array
    {
        preg_match_all('/<(?:input|textarea)\b[^>]*\bname="([^"]+)"[^>]*>/i', $html, $matches);
        return $matches[1];
    }

    /** @return int how many real <input>/<textarea> tags are on the page — every one of these must have contributed both an id and a name above, or the counts below won't match. */
    private function countFields(string $html): int
    {
        preg_match_all('/<(?:input|textarea)\b[^>]*>/i', $html, $matches);
        return count($matches[0]);
    }

    public function test_otp_login_profile_step_gives_every_field_a_unique_id_and_name(): void
    {
        $html = Livewire::test(OtpLogin::class)->set('step', 'profile')->html();

        $ids = $this->extractFieldIds($html);
        $names = $this->extractFieldNames($html);
        $fieldCount = $this->countFields($html);

        // first_name, last_name, national_id, email, address.
        $this->assertSame(5, $fieldCount, 'the profile step should render exactly five fields');
        $this->assertCount($fieldCount, $ids, 'every field must have an id — otherwise Livewire/Alpine has nothing to anchor node identity on for structurally identical siblings');
        $this->assertCount($fieldCount, $names, 'every field must have a name — also needed for password managers/autofill');
        $this->assertSame(count($ids), count(array_unique($ids)), 'no two fields may share an id: ' . json_encode($ids));
        $this->assertSame(count($names), count(array_unique($names)), 'no two fields may share a name: ' . json_encode($names));
    }

    public function test_otp_login_profile_step_wraps_every_field_in_a_uniquely_keyed_element(): void
    {
        $html = Livewire::test(OtpLogin::class)->set('step', 'profile')->html();

        preg_match_all('/wire:key="([^"]+)"/', $html, $matches);
        $keys = $matches[1];

        $this->assertGreaterThanOrEqual(5, count($keys), 'each of the five profile fields should sit behind its own wire:key');
        $this->assertSame(count($keys), count(array_unique($keys)), 'wire:key values must be unique: ' . json_encode($keys));
    }

    public function test_otp_login_phone_and_otp_steps_also_have_unique_field_ids(): void
    {
        foreach (['phone', 'otp'] as $step) {
            $html = Livewire::test(OtpLogin::class)->set('step', $step)->html();
            $ids = $this->extractFieldIds($html);

            $this->assertNotEmpty($ids, "the {$step} step should render at least one identified field");
            $this->assertSame(count($ids), count(array_unique($ids)), "duplicate ids on the {$step} step: " . json_encode($ids));
        }
    }

    public function test_email_login_register_mode_gives_every_field_a_unique_id_and_name(): void
    {
        // The two password fields (password, password_confirmation) are the
        // pair at real risk here — same tag shape as OtpLogin's first_name/
        // last_name.
        $html = Livewire::test(EmailLogin::class)->set('mode', 'register')->html();

        $ids = $this->extractFieldIds($html);
        $names = $this->extractFieldNames($html);
        $fieldCount = $this->countFields($html);

        // name, email, password, password_confirmation — plus one honeypot
        // input (2026-10-07, see App\Support\SignupRisk / EmailLogin::$website)
        // that deliberately carries an id but no name attribute: a password
        // manager/autofill heuristic keys off name far more than id, and a
        // honeypot that a form-filler actually fills in defeats its purpose.
        $this->assertSame(5, $fieldCount, 'register mode should render exactly five fields (four real + one honeypot)');
        $this->assertCount($fieldCount, $ids, 'every field, honeypot included, must have an id');
        $this->assertCount(4, $names, 'only the four real fields should carry a name — the honeypot deliberately has none');
        $this->assertSame(count($ids), count(array_unique($ids)), 'no two fields may share an id: ' . json_encode($ids));
        $this->assertSame(count($names), count(array_unique($names)), 'no two fields may share a name: ' . json_encode($names));
    }

    public function test_email_login_login_mode_gives_every_field_a_unique_id_and_name(): void
    {
        $html = Livewire::test(EmailLogin::class)->set('mode', 'login')->html();

        $ids = $this->extractFieldIds($html);
        $names = $this->extractFieldNames($html);

        $this->assertSame(2, count($ids), 'login mode should render exactly two identified fields (email, password)');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertSame(count($names), count(array_unique($names)));
    }
}
