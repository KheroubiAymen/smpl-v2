<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->forms() as $def) {
            $existing = DB::table('Form')->where('name', $def['name'])->first();

            // If the form exists but has no content rows (partial failure from a
            // previous interrupted run), delete it so we can recreate it cleanly.
            if ($existing) {
                $hasDfc = DB::table('DisplayableFormContent')
                    ->where('parent_type', 'form')
                    ->where('parent_id', $existing->id)
                    ->exists();
                if ($hasDfc) {
                    continue; // fully created — skip
                }
                DB::table('Form')->where('id', $existing->id)->delete();
                Log::warning("[SMPL] Form '{$def['name']}' existed with no content — deleted for recreation");
            }

            DB::transaction(function () use ($def) {
                $etId = $def['entitytype']
                    ? DB::table('EntityType')->where('name', $def['entitytype'])->value('id')
                    : null;

                $formId = DB::table('Form')->insertGetId([
                    'name'                  => $def['name'],
                    'label_position'        => $def['label_position'],
                    'label_size'            => $def['label_size'],
                    'with_default_language' => $def['with_default_language'] ? 1 : 0,
                    'can_add_queries'       => $def['can_add_queries'] ? 1 : 0,
                    'entitytype_id'         => $etId,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);

                foreach ($def['contents'] as $c) {
                    $contentId = null;

                    if ($c['content_type'] === 'field') {
                        $contentId = DB::table('Field')->where('name', $c['content'])->value('id');
                        if (!$contentId) {
                            Log::warning("[SMPL] Form '{$def['name']}': field '{$c['content']}' not found — skipped");
                            continue;
                        }
                    } elseif ($c['content_type'] === 'form_section') {
                        $contentId = DB::table('form_section')->insertGetId([
                            'type'       => $c['section']['type'],
                            'value'      => $c['section']['value'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('DisplayableFormContent')->insert([
                        'parent_type'       => 'form',
                        'parent_id'         => $formId,
                        'content_type'      => $c['content_type'],
                        'content_id'        => $contentId,
                        'position_row'      => $c['row'],
                        'position_col'      => $c['col'],
                        'width'             => $c['width'],
                        'height'            => $c['height'],
                        'condition_to_show' => null,
                        'details'           => json_encode($c['details'] ?? []),
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }

                Log::info("[SMPL] Created form: {$def['name']}");
            });
        }
    }

    public function down(): void {}

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function field(string $name, int $row, int $col, int $w, int $h, array $details = [], string $color = '#000000', int $fontSize = 16): array
    {
        return ['content_type' => 'field', 'content' => $name, 'row' => $row, 'col' => $col, 'width' => $w, 'height' => $h, 'details' => $details, 'color' => $color, 'fontSize' => $fontSize, 'hidden' => 0];
    }

    private function section(string $type, string $value, int $row, int $col, int $w, int $h, string $color = '#000000', int $fontSize = 22): array
    {
        return ['content_type' => 'form_section', 'content' => null, 'section' => ['type' => $type, 'value' => $value], 'row' => $row, 'col' => $col, 'width' => $w, 'height' => $h, 'details' => [], 'color' => $color, 'fontSize' => $fontSize, 'hidden' => 0];
    }

    private function noVal(): array  { return ['default_value' => ['type' => 'no_value'],           'rules' => [],           'read_only' => false, 'parent_value_field' => null]; }
    private function req(): array    { return ['default_value' => ['type' => 'no_value'],           'rules' => ['required'], 'read_only' => false, 'parent_value_field' => null]; }
    private function today(): array  { return ['default_value' => ['type' => 'dynamic', 'value' => 'TODAY_DATE'], 'rules' => [], 'read_only' => false, 'parent_value_field' => null]; }
    private function choice(): array { return ['default_value' => ['type' => 'no_value'], 'rules' => [], 'read_only' => false, 'vertical' => false, 'radio' => false, 'parent_value_field' => null]; }

    // ─── Form definitions ─────────────────────────────────────────────────────

    private function forms(): array
    {
        $f = fn(...$a) => $this->field(...$a);
        $s = fn(...$a) => $this->section(...$a);

        return [
            // ── Creation Prompt ───────────────────────────────────────────────
            [
                'name'                  => 'smpl_creation_prompt',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_CREATION',
                'contents' => [
                    $f('smpl_workflow_line_fk',      1, 1, 6, 4, $this->noVal()),
                    $f('smpl_workflow_line_quantity', 1, 7, 6, 4, $this->noVal()),
                    $f('smpl_barcodes',              5, 1, 6, 6, $this->noVal()),
                ],
            ],

            // ── Generic Event Template (base) ─────────────────────────────────
            [
                'name'                  => 'Generic_event_template',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',        1, 1, 6, 4, $this->noVal()),
                    $f('smpl_event_start_time', 1, 7, 6, 4, $this->today()),
                ],
            ],

            // ── Generic Event Template - Reception ────────────────────────────
            [
                'name'                  => 'Generic_event_template_RECEPTION',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',        1, 1, 6, 4, $this->noVal()),
                    $f('smpl_event_start_time', 1, 7, 6, 4, $this->noVal()),
                ],
            ],

            // ── Generic Event Template - Aliquoting ───────────────────────────
            [
                'name'                  => 'Generic_event_template_ALIQUOTING',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',        1, 1, 5, 4, $this->noVal()),
                    $f('smpl_event_start_time', 1, 6, 7, 4, $this->today()),
                    $f('smpl_consumed_volume',  5, 1, 5, 4, $this->noVal()),
                    $f('smpl_volume_unit',      5, 6, 7, 4, $this->choice()),
                ],
            ],

            // ── Generic Event Template - Centrifugation ───────────────────────
            [
                'name'                  => 'Generic_event_template_CENTRIFUGATION',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',             1, 1, 6, 4, $this->noVal()),
                    $f('smpl_event_start_time',      1, 7, 6, 4, $this->today()),
                    $f('smpl_centrifugation_force',  5, 1, 6, 4, $this->noVal()),
                    $f('smpl_centrifugation_brake',  5, 7, 6, 4, $this->choice()),
                    $f('smpl_event_temp',            9, 1, 6, 4, $this->choice()),
                    $f('smpl_event_duration',        9, 7, 6, 4, $this->noVal()),
                ],
            ],

            // ── Generic Event Template - Collection ───────────────────────────
            [
                'name'                  => 'Generic_event_template_COLLECTION',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',                             1,  1, 6, 4, $this->noVal()),
                    $f('smpl_event_start_time',                     1,  7, 6, 4, $this->today()),
                    $f('smpl_collection_service',                   5,  1, 6, 4, $this->noVal()),
                    $f('smpl_collection_department',                5,  7, 6, 4, $this->noVal()),
                    $f('smpl_collection_special_conditions',        9,  1, 6, 4, $this->noVal()),
                    $f('smpl_collection_special_conditions_other',  9,  7, 6, 4, $this->noVal()),
                ],
            ],

            // ── Generic Event Template - Destruction ──────────────────────────
            [
                'name'                  => 'Generic_event_template_DESTRUCTION',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',        1, 1, 6, 4, $this->noVal()),
                    $f('smpl_event_start_time', 1, 7, 6, 4, $this->today()),
                    $f('smpl_destruction_reason', 5, 1, 6, 5, $this->noVal()),
                ],
            ],

            // ── Generic Event Template - Distribution ─────────────────────────
            [
                'name'                  => 'Generic_event_template_DISTRIBUTION',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',                        1, 1, 6, 4, $this->noVal()),
                    $f('smpl_event_start_time',                 1, 7, 6, 4, $this->today()),
                    $f('smpl_distribution_project_destination', 5, 1, 6, 4, $this->noVal()),
                    $f('smpl_event_reason',                     5, 7, 6, 6, $this->noVal()),
                    $f('smpl_event_temp',                       9, 1, 6, 4, $this->choice()),
                ],
            ],

            // ── Generic Event Template - Storage ──────────────────────────────
            [
                'name'                  => 'Generic_event_template_STORAGE',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_EVENT',
                'contents' => [
                    $f('$_Username_fk',        1,  1, 5, 4, $this->noVal()),
                    $f('smpl_event_start_time', 1,  6, 7, 4, $this->noVal()),
                    $f('STORAGE',              5,  1, 5, 4, $this->noVal()),
                    $f('smpl_storage_temp',    5,  6, 7, 4, $this->choice()),
                    $f('POSITION_ROW',         9,  1, 5, 4, $this->noVal()),
                    $f('POSITION_COLUMN',      9,  6, 7, 4, $this->noVal()),
                ],
            ],

            // ── Workflow Edit ─────────────────────────────────────────────────
            [
                'name'                  => 'smpl_workflow_edit',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_WORKFLOW',
                'contents' => [
                    $f('smpl_study_fk',                1,  1, 6, 4, $this->req()),
                    $f('smpl_label',                   1,  7, 6, 4, $this->noVal()),
                    $f('smpl_case_id_gen_fk',          5,  1, 6, 4, $this->noVal()),
                    $f('smpl_subject_id_gen_fk',       5,  7, 6, 4, $this->noVal()),
                    $f('smpl_kit_id_gen_fk',           9,  1, 6, 4, $this->noVal()),
                    $f('smpl_workflow_is_collection',  9,  7, 6, 3, ['default_value' => ['type' => 'static', 'value' => true], 'rules' => [], 'read_only' => false], '#056704'),
                    $f('smpl_workflow_show_hierarchy', 13, 1, 6, 3, $this->noVal(), '#056704'),
                ],
            ],

            // ── Add new Study ─────────────────────────────────────────────────
            [
                'name'                  => 'Add new Study',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_STUDY',
                'contents' => [
                    $s('IMAGE',  'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQtKV5SiHCmPv1IQqsULt2BPpp3bHgmZC2u3A&s', 1, 1, 2, 20, '#FFFFFF'),
                    $s('HEADER', 'Add New Study',   3, 3, 10, 5, '#783F04'),
                    $f('smpl_label',       8, 3, 4, 4, $this->req()),
                    $f('smpl_description', 8, 7, 6, 9, $this->noVal()),
                ],
            ],

            // ── Workflow Line Edit ────────────────────────────────────────────
            [
                'name'                  => 'smpl_workflow_line_edit',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_WORKFLOW_LINE',
                'contents' => [
                    $f('smpl_label',                  1,  1, 12, 4, $this->req()),
                    $f('smpl_workflow_line_quantity',  5,  1,  4, 4, $this->req()),
                    $f('smpl_workflow_line_suffix',    5,  5,  4, 4, $this->noVal()),
                    $f('smpl_workflow_line_is_kit',    5,  9,  4, 3, $this->noVal()),
                    $f('smpl_id_gen_fk',               9,  1,  4, 4, $this->noVal()),
                    $f('smpl_container_type_fk',       9,  5,  4, 4, $this->noVal()),
                    $f('smpl_container_volume',        9,  9,  4, 4, $this->noVal()),
                    $f('smpl_content_volume',         13,  1,  4, 4, $this->noVal()),
                    $f('smpl_volume_unit',            13,  5,  4, 4, $this->choice()),
                    $f('smpl_workflow_line_color',    13,  9,  4, 4, $this->choice()),
                    $f('smpl_sample_type_fk',         17,  1,  4, 4, $this->noVal()),
                ],
            ],

            // ── Add new Sample Type ───────────────────────────────────────────
            [
                'name'                  => 'Add new Sample type',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_SAMPLE_TYPE',
                'contents' => [
                    $s('IMAGE',  'https://static.vecteezy.com/ti/vecteur-libre/p1/17407814-illustration-de-vecteur-de-dessin-anime-de-tube-a-essai-vectoriel.jpg', 1, 1, 2, 18, '#FFFFFF'),
                    $s('HEADER', 'Add New Sample type', 4, 3, 10, 5, '#45791B'),
                    $f('smpl_label',      9, 3, 5, 4, $this->req()),
                    $f('smpl_sample_type', 9, 8, 5, 4, $this->choice()),
                ],
            ],

            // ── Add a new Container ───────────────────────────────────────────
            [
                'name'                  => 'Add a new Container',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_CONTAINER_TYPE',
                'contents' => [
                    $s('IMAGE',  'https://thumbs.dreamstime.com/b/croquis-color%C3%A9-dessin-du-tube-d-essai-%C3%A0-main-chercheur-sur-fond-blanc-225413074.jpg', 1, 1, 2, 20, '#FFFFFF'),
                    $s('HEADER', 'Add a new Container', 3, 3, 10, 5, '#783F04'),
                    $f('smpl_label',             8, 3, 4, 4, $this->req()),
                    $f('smpl_container_type',    8, 7, 3, 4, $this->choice()),
                    $f('smpl_container_additive', 8, 10, 3, 4, $this->choice()),
                ],
            ],

            // ── Subject Template ──────────────────────────────────────────────
            [
                'name'                  => 'smpl_subject_template',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_SUBJECT',
                'contents' => [
                    $f('smpl_id',         1, 1, 6, 4, $this->noVal()),
                    $f('smpl_subject_id', 1, 7, 6, 4, $this->noVal()),
                ],
            ],

            // ── Case Template ─────────────────────────────────────────────────
            [
                'name'                  => 'smpl_case_template',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_CASE',
                'contents' => [
                    $f('smpl_id',           1, 1, 6, 4, $this->noVal()),
                    $f('smpl_case_id',      1, 7, 6, 4, $this->noVal()),
                    $f('smpl_case_type_fk', 5, 1, 6, 4, $this->noVal()),
                    $f('smpl_subject_fk',   5, 7, 6, 4, $this->noVal()),
                ],
            ],

            // ── Kit Template ──────────────────────────────────────────────────
            [
                'name'                  => 'smpl_kit_template',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_KIT',
                'contents' => [
                    $f('smpl_id',            1, 1, 6, 4, $this->noVal()),
                    $f('smpl_kit_id',        1, 7, 6, 4, $this->noVal()),
                    $f('smpl_study_fk',      5, 1, 6, 4, $this->noVal()),
                    $f('smpl_kit_status_fk', 5, 7, 6, 4, $this->noVal()),
                    $f('smpl_subject_fk',    9, 1, 6, 4, $this->noVal()),
                    $f('smpl_case_fk',       9, 7, 6, 4, $this->noVal()),
                    $f('smpl_kit_is_real',  13, 1, 6, 3, $this->noVal()),
                ],
            ],

            // ── Generic Event Template - Morphological ────────────────────────
            [
                'name'                  => 'Generic_event_template_morphological',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_MORPHOLOGICAL_CODE',
                'contents' => [
                    $f('smpl_label',       1, 1, 6, 4, $this->req()),
                    $f('smpl_description', 1, 7, 6, 9, $this->noVal()),
                ],
            ],

            // ── Generic Event Template - Topographical ────────────────────────
            [
                'name'                  => 'Generic_event_template_topographical',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_TOPOGRAPHICAL_CODE',
                'contents' => [
                    $f('smpl_label',       1, 1, 6, 4, $this->req()),
                    $f('smpl_description', 1, 7, 6, 9, $this->noVal()),
                ],
            ],

            // ── Workflow Step Edit ────────────────────────────────────────────
            [
                'name'                  => 'smpl_workflow_step_edit',
                'label_position'        => 'outlined',
                'label_size'            => '1/4',
                'with_default_language' => true,
                'can_add_queries'       => false,
                'entitytype'            => 'SMPL_WORKFLOW_STEP',
                'contents' => [
                    $f('smpl_event_type_fk',             1, 1, 6, 4, $this->req()),
                    $f('smpl_label',                     1, 7, 6, 4, $this->noVal()),
                    $f('smpl_sample_status_fk',          5, 1, 6, 4, $this->noVal()),
                    $f('smpl_workflow_step_is_optional', 5, 7, 6, 3, $this->noVal(), '#056704'),
                    $f('smpl_workflow_step_goto_fk',     9, 1, 6, 4, $this->noVal()),
                ],
            ],
        ];
    }
};
