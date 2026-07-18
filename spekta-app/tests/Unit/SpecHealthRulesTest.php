<?php

namespace Tests\Unit;

use App\Services\SpecHealthValidator;
use PHPUnit\Framework\TestCase;

class SpecHealthRulesTest extends TestCase
{
    public function test_erd_entity_missing_in_api_flagged(): void
    {
        $db = "```mermaid\nerDiagram\n  users {\n    uuid id PK\n  }\n  invoices {\n    uuid id PK\n  }\n```";
        $api = '## GET /api/users';

        $findings = SpecHealthValidator::erdApiFindings($db, $api);

        $this->assertCount(1, $findings);
        $this->assertSame('erd_entity_in_api', $findings[0]['rule_key']);
        $this->assertStringContainsString('invoices', $findings[0]['message']);
    }

    public function test_erd_rule_silent_without_erd(): void
    {
        $this->assertSame([], SpecHealthValidator::erdApiFindings('# DATABASE tanpa diagram', '## API'));
    }

    public function test_duplicate_fr_flagged(): void
    {
        $docs = ['REQUIREMENTS' => "### FR-01: A\n- ac\n### FR-01: B\n- ac", 'PRD' => 'FR-01'];

        $keys = array_column(SpecHealthValidator::numberingFindings($docs), 'rule_key');

        $this->assertContains('fr_duplicate', $keys);
    }

    public function test_dangling_fr_ref_flagged_but_prd_frs_excluded(): void
    {
        $docs = [
            'PRD' => 'FR-01 dan FR-02',                       // FR-02 hilang = urusan rule fr_has_ac, bukan dangling
            'REQUIREMENTS' => "### FR-01: A\n- ac",
            'ROADMAP' => 'FR-01, FR-09 di fase 2',            // FR-09 tidak terdefinisi & tidak di PRD → dangling
        ];

        $findings = SpecHealthValidator::numberingFindings($docs);

        $this->assertCount(1, $findings);
        $this->assertSame('fr_dangling_ref', $findings[0]['rule_key']);
        $this->assertStringContainsString('FR-09', $findings[0]['message']);
    }
}
