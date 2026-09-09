<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->views() as $def) {
            $etId = DB::table('EntityType')->where('name', $def['entitytype'])->value('id');
            if (!$etId) {
                Log::warning("[SMPL] Custom view '{$def['name']}': entity type '{$def['entitytype']}' not found — skipped");
                continue;
            }

            if (DB::table('customView')->where('name', $def['name'])->where('entitytype_id', $etId)->exists()) {
                continue;
            }

            DB::transaction(function () use ($def, $etId) {
                $sortJson = null;
                if (!empty($def['sort_field'])) {
                    $sortFieldId = DB::table('Field')->where('name', $def['sort_field'])->value('id');
                    if ($sortFieldId) {
                        $sortJson = json_encode([['sort_by' => $sortFieldId, 'sort_type' => $def['sort_dir'] ?? 'ASC']]);
                    }
                }

                $viewId = DB::table('customView')->insertGetId([
                    'name'              => $def['name'],
                    'entitytype_id'     => $etId,
                    'type'              => 'DATAVIEW',
                    'filters'           => null,
                    'selected_entities' => '[]',
                    'rows_per_page'     => $def['rows_per_page'] ?? 50,
                    'sort'              => $sortJson,
                    'position'          => 0,
                    'is_scan_mode'      => 0,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                $pos = 1;
                foreach ($def['fields'] as $fieldName) {
                    $fieldId = DB::table('Field')->where('name', $fieldName)->value('id');
                    if (!$fieldId) {
                        Log::warning("[SMPL] Custom view '{$def['name']}': field '$fieldName' not found — skipped");
                        continue;
                    }
                    DB::table('customViewColumn')->insert([
                        'customview_id' => $viewId,
                        'field_id'      => $fieldId,
                        'position'      => $pos++,
                        'width'         => null,
                        'pinned'        => null,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }

                Log::info("[SMPL] Created custom view: {$def['name']} ({$def['entitytype']})");
            });
        }

        Artisan::call('cache:clear');
        Log::info('[SMPL] Cache cleared after migration');
    }

    public function down(): void
    {
        $names = array_column($this->views(), 'name');
        $ids   = DB::table('customView')->whereIn('name', $names)->pluck('id');
        DB::table('customViewColumn')->whereIn('customview_id', $ids)->delete();
        DB::table('customView')->whereIn('id', $ids)->delete();
    }

    // ─── View definitions ─────────────────────────────────────────────────────

    private function views(): array
    {
        return [

            // ── SMPL_SUBJECT ─────────────────────────────────────────────────

            [
                'name'       => 'Subjects',
                'entitytype' => 'SMPL_SUBJECT',
                'sort_field' => 'smpl_id',
                'sort_dir'   => 'ASC',
                'fields'     => ['smpl_id', 'smpl_subject_id'],
            ],
            [
                'name'       => 'Subjects - by Subject ID',
                'entitytype' => 'SMPL_SUBJECT',
                'sort_field' => 'smpl_subject_id',
                'sort_dir'   => 'ASC',
                'fields'     => ['smpl_subject_id', 'smpl_id'],
            ],
            [
                'name'       => 'Subjects - Full',
                'entitytype' => 'SMPL_SUBJECT',
                'sort_field' => 'smpl_id',
                'sort_dir'   => 'DESC',
                'fields'     => ['smpl_id', 'smpl_subject_id'],
            ],

            // ── SMPL_CASE ─────────────────────────────────────────────────────

            [
                'name'       => 'Cases',
                'entitytype' => 'SMPL_CASE',
                'sort_field' => 'smpl_id',
                'sort_dir'   => 'ASC',
                'fields'     => ['smpl_id', 'smpl_case_id', 'smpl_case_type_fk', 'smpl_subject_fk'],
            ],
            [
                'name'       => 'Cases - by Type',
                'entitytype' => 'SMPL_CASE',
                'sort_field' => 'smpl_case_type_fk',
                'sort_dir'   => 'ASC',
                'fields'     => ['smpl_case_type_fk', 'smpl_id', 'smpl_case_id', 'smpl_subject_fk'],
            ],
            [
                'name'       => 'Cases - by Subject',
                'entitytype' => 'SMPL_CASE',
                'sort_field' => 'smpl_subject_fk',
                'sort_dir'   => 'ASC',
                'fields'     => ['smpl_subject_fk', 'smpl_id', 'smpl_case_id', 'smpl_case_type_fk'],
            ],

            // ── SMPL_SAMPLE ───────────────────────────────────────────────────

            [
                'name'       => 'Samples',
                'entitytype' => 'SMPL_SAMPLE',
                'sort_field' => 'smpl_id',
                'sort_dir'   => 'ASC',
                'fields'     => [
                    'smpl_id', 'smpl_sample_id', 'smpl_sample_status_fk',
                    'smpl_study_fk', 'smpl_kit_fk', 'smpl_subject_fk',
                ],
            ],
            [
                'name'       => 'Samples - by Status',
                'entitytype' => 'SMPL_SAMPLE',
                'sort_field' => 'smpl_sample_status_fk',
                'sort_dir'   => 'ASC',
                'fields'     => [
                    'smpl_sample_status_fk', 'smpl_id', 'smpl_sample_id',
                    'smpl_kit_fk', 'smpl_subject_fk', 'smpl_workflow_step_fk',
                ],
            ],
            [
                'name'       => 'Samples - by Kit',
                'entitytype' => 'SMPL_SAMPLE',
                'sort_field' => 'smpl_kit_fk',
                'sort_dir'   => 'ASC',
                'fields'     => [
                    'smpl_kit_fk', 'smpl_id', 'smpl_sample_id',
                    'smpl_sample_status_fk', 'smpl_order',
                    'smpl_workflow_line_fk', 'smpl_workflow_step_fk',
                ],
            ],
        ];
    }
};
