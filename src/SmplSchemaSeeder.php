<?php

namespace SwissDidata\Smpl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeds SMPL entity types and fields via direct DB inserts,
 * bypassing DiData's permission checks (which reject unauthenticated callers).
 */
class SmplSchemaSeeder
{
    private int $created = 0;

    public function seed(): int
    {
        $idFieldId = DB::table('Field')->where('name', 'id')->where('field_system', 1)->value('id');
        if (!$idFieldId) {
            Log::warning('[SMPL] System id field not found — cannot seed entity schema');
            return 0;
        }

        foreach ($this->entityTypeDefinitions() as $def) {
            $this->ensureEntityType($def['name'], $def['label'], $def['context'], $idFieldId);
        }

        foreach ($this->basicFieldDefinitions() as $def) {
            $this->ensureBasicField($def['name'], $def['label'], $def['datatype']);
        }

        foreach ($this->foreignFieldDefinitions() as $def) {
            $targetId = DB::table('EntityType')->where('name', $def['target'])->value('id');
            if (!$targetId) continue;
            $this->ensureForeignField($def['name'], $def['label'], $targetId, $def['multiple'] ?? false);
        }

        foreach ($this->fieldAttachments() as [$etName, $fieldName]) {
            $etId    = DB::table('EntityType')->where('name', $etName)->value('id');
            $fieldId = DB::table('Field')->where('name', $fieldName)->value('id');
            if ($etId && $fieldId) {
                $this->attachField($etId, $fieldId);
            }
        }

        $this->updateShownFields();
        $this->updateForeignChoiceShownFields();

        Log::info('[SMPL] Entity schema seeding completed — ' . $this->created . ' items created');
        return $this->created;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function ensureEntityType(string $name, string $label, int $ctx, int $idFieldId): void
    {
        if (DB::table('EntityType')->where('name', $name)->exists()) return;
        try {
            $etId = DB::table('EntityType')->insertGetId([
                'name'           => $name,
                'label'          => $label,
                'context_id'     => $ctx,
                'is_system'      => 0,
                'shown_field_id' => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            // Attach system id field
            DB::table('fieldentitytype')->insert([
                'field_id'      => $idFieldId,
                'entitytype_id' => $etId,
                'position'      => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            // Set shown_field_id to id field
            DB::table('EntityType')->where('id', $etId)->update(['shown_field_id' => $idFieldId]);
            $this->created++;
            Log::info("[SMPL] Created entity type: $name");
        } catch (\Throwable $e) {
            Log::warning("[SMPL] Could not create entity type $name: " . $e->getMessage());
        }
    }

    private function ensureBasicField(string $name, string $label, string $datatype): void
    {
        if (DB::table('Field')->where('name', $name)->exists()) return;
        try {
            $subtypeId = DB::table('BasicField')->insertGetId([
                'datatype'   => $datatype,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('Field')->insert([
                'name'             => $name,
                'label'            => $label,
                'fieldtype'        => 'BASIC',
                'fieldtype_id'     => $subtypeId,
                'field_system'     => 0,
                'tooltip_type'     => 'STATIC',
                'hidden'           => 0,
                'read_only'        => 0,
                'sensitive'        => 0,
                'is_required'      => 0,
                'lock_after_entry' => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $this->created++;
            Log::info("[SMPL] Created basic field: $name ($datatype)");
        } catch (\Throwable $e) {
            Log::warning("[SMPL] Could not create basic field $name: " . $e->getMessage());
        }
    }

    private function ensureForeignField(string $name, string $label, int $targetId, bool $multiple): void
    {
        if (DB::table('Field')->where('name', $name)->exists()) return;
        try {
            $subtypeId = DB::table('ForeignChoiceField')->insertGetId([
                'target_entitytype_id'    => $targetId,
                'multiple'                => $multiple ? 1 : 0,
                'scalable_link_to_entity' => 0,
                'drop_down_columns'       => '[]',
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
            DB::table('Field')->insert([
                'name'             => $name,
                'label'            => $label,
                'fieldtype'        => 'FOREIGN',
                'fieldtype_id'     => $subtypeId,
                'field_system'     => 0,
                'tooltip_type'     => 'STATIC',
                'hidden'           => 0,
                'read_only'        => 0,
                'sensitive'        => 0,
                'is_required'      => 0,
                'lock_after_entry' => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $this->created++;
            Log::info("[SMPL] Created foreign field: $name");
        } catch (\Throwable $e) {
            Log::warning("[SMPL] Could not create foreign field $name: " . $e->getMessage());
        }
    }

    private function attachField(int $etId, int $fieldId): void
    {
        if (DB::table('fieldentitytype')->where('entitytype_id', $etId)->where('field_id', $fieldId)->exists()) return;
        try {
            $pos = (DB::table('fieldentitytype')->where('entitytype_id', $etId)->max('position') ?? 0) + 1;
            DB::table('fieldentitytype')->insert([
                'field_id'      => $fieldId,
                'entitytype_id' => $etId,
                'position'      => $pos,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            $etName    = DB::table('EntityType')->where('id', $etId)->value('name') ?? $etId;
            $fieldName = DB::table('Field')->where('id', $fieldId)->value('name') ?? $fieldId;
            Log::warning("[SMPL] Could not attach $fieldName to $etName: " . $e->getMessage());
        }
    }

    // ─── Definitions ─────────────────────────────────────────────────────────

    private function entityTypeDefinitions(): array
    {
        return [
            ['name' => 'SMPL_STUDY',         'label' => 'Study',         'context' => 1],
            ['name' => 'SMPL_STATUS',         'label' => 'Sample Status', 'context' => 1],
            ['name' => 'SMPL_KIT_STATUS',     'label' => 'Kit Status',    'context' => 1],
            ['name' => 'SMPL_EVENT_TYPE',     'label' => 'Event Type',    'context' => 1],
            ['name' => 'SMPL_ID_GENERATOR',   'label' => 'ID Generator',  'context' => 1],
            ['name' => 'SMPL_WORKFLOW',       'label' => 'Workflow',      'context' => 1],
            ['name' => 'SMPL_WORKFLOW_LINE',  'label' => 'Workflow Line', 'context' => 1],
            ['name' => 'SMPL_WORKFLOW_STEP',  'label' => 'Workflow Step', 'context' => 1],
            ['name' => 'SMPL_CASE_TYPE',      'label' => 'Case Type',     'context' => 1],
            ['name' => 'SMPL_SUBJECT',        'label' => 'Subject',       'context' => 1],
            ['name' => 'SMPL_CASE',           'label' => 'Case',          'context' => 1],
            ['name' => 'SMPL_KIT',            'label' => 'Kit',           'context' => 1],
            ['name' => 'SMPL_SAMPLE',         'label' => 'Sample',        'context' => 1],
            ['name' => 'SMPL_EVENT',          'label' => 'Event',         'context' => 1],
            ['name' => 'SMPL_CREATION',       'label' => 'Creation',      'context' => 1],
            ['name' => 'SMPL_SAMPLE_TYPE',                    'label' => 'Sample Type',              'context' => 1],
            ['name' => 'SMPL_CONTAINER_TYPE',                  'label' => 'Container Type',            'context' => 1],
            ['name' => 'SMPL_REQUEST',                         'label' => 'Request',                   'context' => 1],
            ['name' => 'TIMEPOINT_UND',                        'label' => 'Timepoint',                 'context' => 1],
            ['name' => 'SMPL_MORPHOLOGICAL_CODE',              'label' => 'Morphological Code',        'context' => 1],
            ['name' => 'SMPL_TOPOGRAPHICAL_CODE',              'label' => 'Topographical Code',        'context' => 1],
            ['name' => 'SMPL_STRAIN_RESISTANCE_ANTIMICROBIALS','label' => 'Strain Resistance',         'context' => 1],
            ['name' => 'SMPL_COUNTRY',                         'label' => 'Country',                   'context' => 1],
            ['name' => 'SMPL_COLLECTION',                      'label' => 'Collection',                'context' => 1],
            ['name' => 'SMPL_DERIVATION',                      'label' => 'Derivation',                'context' => 1],
        ];
    }

    private function basicFieldDefinitions(): array
    {
        return [
            ['name' => 'smpl_label',                      'label' => 'Label',               'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_order',                      'label' => 'Order',               'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_id',                         'label' => 'SMPL ID',             'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_status_is_active',           'label' => 'Is Active',           'datatype' => 'BOOL'],
            ['name' => 'smpl_status_seq_number',          'label' => 'Seq Number',          'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_seq_num',                    'label' => 'Seq Num',             'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_template_form_id',           'label' => 'Template Form',       'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_yields_derivative',          'label' => 'Yields Derivative',   'datatype' => 'BOOL'],
            ['name' => 'smpl_is_alias',                   'label' => 'Is Alias',            'datatype' => 'BOOL'],
            ['name' => 'smpl_id_generator_mask',          'label' => 'ID Mask',             'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_id_generator_nb_length',     'label' => 'Number Length',       'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_id_generator_separator',     'label' => 'Separator',           'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_workflow_uses_cases',        'label' => 'Uses Cases',          'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_uses_kits',         'label' => 'Uses Kits',           'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_is_collection',     'label' => 'Is Collection',       'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_show_hierarchy',    'label' => 'Show Hierarchy',      'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_subject_form_id',   'label' => 'Subject Form',        'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_workflow_case_form_id',      'label' => 'Case Form',           'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_workflow_kit_form_id',       'label' => 'Kit Form',            'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_workflow_collection_form_id','label' => 'Collection Form',     'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_sample_creation_form_id',   'label' => 'Sample Creation Form','datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_workflow_line_is_kit',       'label' => 'Is Kit Line',         'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_line_color',        'label' => 'Color',               'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_workflow_line_quantity',     'label' => 'Quantity',            'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_workflow_step_is_batch',     'label' => 'Is Batch',            'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_step_form_id',      'label' => 'Step Form',           'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_workflow_step_is_optional',  'label' => 'Is Optional',         'datatype' => 'BOOL'],
            ['name' => 'smpl_workflow_step_then',         'label' => 'Then',                'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_workflow_step_is_repeatable','label' => 'Is Repeatable',       'datatype' => 'BOOL'],
            ['name' => 'Custom_View_ID_Step',             'label' => 'Custom View ID',      'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_case_type_label',            'label' => 'Case Type Label',     'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_id_stem',                    'label' => 'ID Stem',             'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_id_nb',                      'label' => 'ID Number',           'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_sample_id',                  'label' => 'Sample ID',           'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_kit_id',                     'label' => 'Kit ID',              'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_case_id',                    'label' => 'Case ID',             'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_subject_id',                 'label' => 'Subject ID',          'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_kit_is_real',                'label' => 'Is Real Kit',         'datatype' => 'BOOL'],
            ['name' => 'smpl_event_start_time',           'label' => 'Event Start Time',    'datatype' => 'DATETIME'],
            ['name' => 'smpl_barcodes',                          'label' => 'Barcodes',                    'datatype' => 'LONG_TEXT'],
            ['name' => 'smpl_description',                       'label' => 'Description',                 'datatype' => 'LONG_TEXT'],
            ['name' => 'smpl_consumed_volume',                   'label' => 'Consumed Volume',             'datatype' => 'DECIMAL_NUMBER'],
            ['name' => 'smpl_volume_unit',                       'label' => 'Volume Unit',                 'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_centrifugation_force',              'label' => 'Centrifugation Force',        'datatype' => 'DECIMAL_NUMBER'],
            ['name' => 'smpl_centrifugation_brake',              'label' => 'Centrifugation Brake',        'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_event_temp',                        'label' => 'Temperature',                 'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_event_duration',                    'label' => 'Duration (min)',              'datatype' => 'WHOLE_NUMBER'],
            ['name' => 'smpl_collection_department',             'label' => 'Department',                  'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_collection_service',                'label' => 'Service',                     'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_collection_special_conditions',     'label' => 'Special Conditions',          'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_collection_special_conditions_other','label' => 'Special Conditions Other',   'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_distribution_project_destination',  'label' => 'Project Destination',        'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_event_reason',                      'label' => 'Reason',                     'datatype' => 'LONG_TEXT'],
            ['name' => 'smpl_storage_temp',                      'label' => 'Storage Temperature',        'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_destruction_reason',                'label' => 'Destruction Reason',         'datatype' => 'LONG_TEXT'],
            ['name' => 'smpl_workflow_line_suffix',              'label' => 'Line Suffix',                'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_container_volume',                  'label' => 'Container Volume',           'datatype' => 'DECIMAL_NUMBER'],
            ['name' => 'smpl_content_volume',                    'label' => 'Content Volume',             'datatype' => 'DECIMAL_NUMBER'],
            ['name' => 'smpl_container_type',                    'label' => 'Container Type',             'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_container_additive',                'label' => 'Additive',                   'datatype' => 'SHORT_TEXT'],
            ['name' => 'smpl_sample_type',                       'label' => 'Sample Type',                'datatype' => 'SHORT_TEXT'],
        ];
    }

    private function foreignFieldDefinitions(): array
    {
        return [
            ['name' => 'smpl_study_fk',                    'label' => 'Study',            'target' => 'SMPL_STUDY',         'multiple' => false],
            ['name' => 'smpl_workflow_fk',                 'label' => 'Workflow',         'target' => 'SMPL_WORKFLOW',      'multiple' => true],
            ['name' => 'smpl_workflow_line_fk',            'label' => 'Workflow Line',    'target' => 'SMPL_WORKFLOW_LINE', 'multiple' => false],
            ['name' => 'smpl_workflow_lines_fk',           'label' => 'Workflow Lines',   'target' => 'SMPL_WORKFLOW_LINE', 'multiple' => true],
            ['name' => 'smpl_workflow_step_fk',            'label' => 'Workflow Step',    'target' => 'SMPL_WORKFLOW_STEP', 'multiple' => false],
            ['name' => 'smpl_workflow_step_batch_fk',      'label' => 'Batch Step',       'target' => 'SMPL_WORKFLOW_STEP', 'multiple' => false],
            ['name' => 'smpl_workflow_step_goto_fk',       'label' => 'Go To Step',       'target' => 'SMPL_WORKFLOW_STEP', 'multiple' => false],
            ['name' => 'smpl_event_type_fk',               'label' => 'Event Type',       'target' => 'SMPL_EVENT_TYPE',    'multiple' => false],
            ['name' => 'smpl_sample_status_fk',            'label' => 'Sample Status',    'target' => 'SMPL_STATUS',        'multiple' => false],
            ['name' => 'smpl_workflow_step_status_change_fk','label' => 'Status Change',  'target' => 'SMPL_STATUS',        'multiple' => false],
            ['name' => 'smpl_kit_status_fk',               'label' => 'Kit Status',       'target' => 'SMPL_KIT_STATUS',    'multiple' => false],
            ['name' => 'smpl_id_gen_fk',                   'label' => 'ID Generator',     'target' => 'SMPL_ID_GENERATOR',  'multiple' => false],
            ['name' => 'smpl_subject_id_gen_fk',           'label' => 'Subject ID Gen',   'target' => 'SMPL_ID_GENERATOR',  'multiple' => false],
            ['name' => 'smpl_case_id_gen_fk',              'label' => 'Case ID Gen',      'target' => 'SMPL_ID_GENERATOR',  'multiple' => false],
            ['name' => 'smpl_kit_id_gen_fk',               'label' => 'Kit ID Gen',       'target' => 'SMPL_ID_GENERATOR',  'multiple' => false],
            ['name' => 'smpl_case_type_fk',                'label' => 'Case Type',        'target' => 'SMPL_CASE_TYPE',     'multiple' => false],
            ['name' => 'smpl_case_type_workflow_fk',       'label' => 'Case Types',       'target' => 'SMPL_CASE_TYPE',     'multiple' => true],
            ['name' => 'smpl_subject_fk',                  'label' => 'Subject',          'target' => 'SMPL_SUBJECT',       'multiple' => false],
            ['name' => 'smpl_case_fk',                     'label' => 'Case',             'target' => 'SMPL_CASE',          'multiple' => false],
            ['name' => 'smpl_kit_fk',                      'label' => 'Kit',              'target' => 'SMPL_KIT',           'multiple' => false],
            ['name' => 'smpl_sample_fk',                   'label' => 'Parent Sample',    'target' => 'SMPL_SAMPLE',        'multiple' => false],
            ['name' => 'smpl_samples_fk',                  'label' => 'Samples',          'target' => 'SMPL_SAMPLE',        'multiple' => true],
            ['name' => 'smpl_events_fk',                   'label' => 'Events',           'target' => 'SMPL_EVENT',         'multiple' => true],
            ['name' => 'smpl_first_reception',             'label' => 'First Reception',  'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_last_reception',              'label' => 'Last Reception',   'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_first_transportation',        'label' => 'First Transport',  'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_last_transportation',         'label' => 'Last Transport',   'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_first_storage',               'label' => 'First Storage',    'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_last_storage',                'label' => 'Last Storage',     'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_first_centrifugation',        'label' => 'First Centrifug.', 'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_last_centrifugation',         'label' => 'Last Centrifug.',  'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_first_analysis',              'label' => 'First Analysis',   'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_last_analysis',               'label' => 'Last Analysis',    'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_first_processing',            'label' => 'First Processing', 'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_last_processing',             'label' => 'Last Processing',  'target' => 'SMPL_EVENT',         'multiple' => false],
            ['name' => 'smpl_container_type_fk',           'label' => 'Container Type',   'target' => 'SMPL_CONTAINER_TYPE','multiple' => false],
            ['name' => 'smpl_sample_type_fk',              'label' => 'Sample Type',      'target' => 'SMPL_SAMPLE_TYPE',  'multiple' => false],
        ];
    }

    private function fieldAttachments(): array
    {
        return [
            ['SMPL_STUDY',         'smpl_label'],

            ['SMPL_STATUS',        'smpl_label'],
            ['SMPL_STATUS',        'smpl_status_is_active'],
            ['SMPL_STATUS',        'smpl_status_seq_number'],

            ['SMPL_KIT_STATUS',    'smpl_label'],
            ['SMPL_KIT_STATUS',    'smpl_seq_num'],

            ['SMPL_EVENT_TYPE',    'smpl_label'],
            ['SMPL_EVENT_TYPE',    'smpl_template_form_id'],
            ['SMPL_EVENT_TYPE',    'smpl_yields_derivative'],
            ['SMPL_EVENT_TYPE',    'smpl_is_alias'],

            ['SMPL_ID_GENERATOR',  'smpl_id_generator_mask'],
            ['SMPL_ID_GENERATOR',  'smpl_id_generator_nb_length'],
            ['SMPL_ID_GENERATOR',  'smpl_id_generator_separator'],

            ['SMPL_WORKFLOW',      'smpl_label'],
            ['SMPL_WORKFLOW',      'smpl_study_fk'],
            ['SMPL_WORKFLOW',      'smpl_workflow_uses_cases'],
            ['SMPL_WORKFLOW',      'smpl_workflow_uses_kits'],
            ['SMPL_WORKFLOW',      'smpl_workflow_is_collection'],
            ['SMPL_WORKFLOW',      'smpl_workflow_show_hierarchy'],
            ['SMPL_WORKFLOW',      'smpl_workflow_subject_form_id'],
            ['SMPL_WORKFLOW',      'smpl_workflow_case_form_id'],
            ['SMPL_WORKFLOW',      'smpl_workflow_kit_form_id'],
            ['SMPL_WORKFLOW',      'smpl_workflow_collection_form_id'],
            ['SMPL_WORKFLOW',      'smpl_sample_creation_form_id'],
            ['SMPL_WORKFLOW',      'smpl_subject_id_gen_fk'],
            ['SMPL_WORKFLOW',      'smpl_case_id_gen_fk'],
            ['SMPL_WORKFLOW',      'smpl_kit_id_gen_fk'],
            ['SMPL_WORKFLOW',      'smpl_case_type_workflow_fk'],

            ['SMPL_WORKFLOW_LINE', 'smpl_label'],
            ['SMPL_WORKFLOW_LINE', 'smpl_order'],
            ['SMPL_WORKFLOW_LINE', 'smpl_workflow_fk'],
            ['SMPL_WORKFLOW_LINE', 'smpl_workflow_line_fk'],
            ['SMPL_WORKFLOW_LINE', 'smpl_id_gen_fk'],
            ['SMPL_WORKFLOW_LINE', 'smpl_workflow_line_is_kit'],
            ['SMPL_WORKFLOW_LINE', 'smpl_workflow_line_color'],
            ['SMPL_WORKFLOW_LINE', 'smpl_workflow_line_quantity'],

            ['SMPL_WORKFLOW_STEP', 'smpl_label'],
            ['SMPL_WORKFLOW_STEP', 'smpl_order'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_fk'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_line_fk'],
            ['SMPL_WORKFLOW_STEP', 'smpl_event_type_fk'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_is_batch'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_batch_fk'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_form_id'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_is_optional'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_then'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_goto_fk'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_is_repeatable'],
            ['SMPL_WORKFLOW_STEP', 'smpl_sample_status_fk'],
            ['SMPL_WORKFLOW_STEP', 'smpl_workflow_step_status_change_fk'],
            ['SMPL_WORKFLOW_STEP', 'Custom_View_ID_Step'],

            ['SMPL_CASE_TYPE',     'smpl_label'],
            ['SMPL_CASE_TYPE',     'smpl_case_type_label'],
            ['SMPL_CASE_TYPE',     'smpl_workflow_fk'],
            ['SMPL_CASE_TYPE',     'smpl_workflow_lines_fk'],

            ['SMPL_SUBJECT',       'smpl_id'],
            ['SMPL_SUBJECT',       'smpl_subject_id'],

            ['SMPL_CASE',          'smpl_id'],
            ['SMPL_CASE',          'smpl_case_id'],
            ['SMPL_CASE',          'smpl_case_type_fk'],
            ['SMPL_CASE',          'smpl_subject_fk'],

            ['SMPL_KIT',           'smpl_id'],
            ['SMPL_KIT',           'smpl_kit_id'],
            ['SMPL_KIT',           'smpl_kit_is_real'],
            ['SMPL_KIT',           'smpl_study_fk'],
            ['SMPL_KIT',           'smpl_kit_status_fk'],
            ['SMPL_KIT',           'smpl_subject_fk'],
            ['SMPL_KIT',           'smpl_case_fk'],

            ['SMPL_SAMPLE',        'smpl_id'],
            ['SMPL_SAMPLE',        'smpl_id_stem'],
            ['SMPL_SAMPLE',        'smpl_id_nb'],
            ['SMPL_SAMPLE',        'smpl_sample_id'],
            ['SMPL_SAMPLE',        'smpl_kit_id'],
            ['SMPL_SAMPLE',        'smpl_case_id'],
            ['SMPL_SAMPLE',        'smpl_subject_id'],
            ['SMPL_SAMPLE',        'smpl_order'],
            ['SMPL_SAMPLE',        'smpl_study_fk'],
            ['SMPL_SAMPLE',        'smpl_workflow_fk'],
            ['SMPL_SAMPLE',        'smpl_workflow_line_fk'],
            ['SMPL_SAMPLE',        'smpl_workflow_step_fk'],
            ['SMPL_SAMPLE',        'smpl_sample_status_fk'],
            ['SMPL_SAMPLE',        'smpl_kit_fk'],
            ['SMPL_SAMPLE',        'smpl_case_fk'],
            ['SMPL_SAMPLE',        'smpl_subject_fk'],
            ['SMPL_SAMPLE',        'smpl_sample_fk'],
            ['SMPL_SAMPLE',        'smpl_events_fk'],
            ['SMPL_SAMPLE',        'smpl_first_reception'],
            ['SMPL_SAMPLE',        'smpl_last_reception'],
            ['SMPL_SAMPLE',        'smpl_first_transportation'],
            ['SMPL_SAMPLE',        'smpl_last_transportation'],
            ['SMPL_SAMPLE',        'smpl_first_storage'],
            ['SMPL_SAMPLE',        'smpl_last_storage'],
            ['SMPL_SAMPLE',        'smpl_first_centrifugation'],
            ['SMPL_SAMPLE',        'smpl_last_centrifugation'],
            ['SMPL_SAMPLE',        'smpl_first_analysis'],
            ['SMPL_SAMPLE',        'smpl_last_analysis'],
            ['SMPL_SAMPLE',        'smpl_first_processing'],
            ['SMPL_SAMPLE',        'smpl_last_processing'],

            ['SMPL_EVENT',         'smpl_event_start_time'],
            ['SMPL_EVENT',         'smpl_event_type_fk'],
            ['SMPL_EVENT',         'smpl_workflow_step_fk'],
            ['SMPL_EVENT',         'smpl_samples_fk'],
            ['SMPL_EVENT',         'smpl_kit_fk'],
            ['SMPL_EVENT',         'smpl_subject_fk'],
            ['SMPL_EVENT',         'smpl_case_fk'],

            ['SMPL_CREATION',      'smpl_workflow_line_fk'],
            ['SMPL_CREATION',      'smpl_workflow_line_quantity'],
            ['SMPL_CREATION',      'smpl_barcodes'],

            ['SMPL_SAMPLE_TYPE',    'smpl_label'],
            ['SMPL_SAMPLE_TYPE',    'smpl_sample_type'],

            ['SMPL_CONTAINER_TYPE', 'smpl_label'],
            ['SMPL_CONTAINER_TYPE', 'smpl_container_type'],
            ['SMPL_CONTAINER_TYPE', 'smpl_container_volume'],
            ['SMPL_CONTAINER_TYPE', 'smpl_container_additive'],

            ['SMPL_REQUEST',                          'smpl_label'],
            ['SMPL_REQUEST',                          'smpl_description'],
            ['TIMEPOINT_UND',                         'smpl_label'],
            ['TIMEPOINT_UND',                         'smpl_description'],
            ['SMPL_MORPHOLOGICAL_CODE',               'smpl_label'],
            ['SMPL_MORPHOLOGICAL_CODE',               'smpl_description'],
            ['SMPL_TOPOGRAPHICAL_CODE',               'smpl_label'],
            ['SMPL_TOPOGRAPHICAL_CODE',               'smpl_description'],
            ['SMPL_STRAIN_RESISTANCE_ANTIMICROBIALS', 'smpl_label'],
            ['SMPL_STRAIN_RESISTANCE_ANTIMICROBIALS', 'smpl_description'],
            ['SMPL_COUNTRY',                          'smpl_label'],
            ['SMPL_COLLECTION',                       'smpl_label'],
            ['SMPL_DERIVATION',                       'smpl_label'],
        ];
    }

    private function updateForeignChoiceShownFields(): void
    {
        $mappings = [
            // → smpl_label
            'smpl_study_fk'                       => 'smpl_label',
            'smpl_workflow_fk'                    => 'smpl_label',
            'smpl_workflow_line_fk'               => 'smpl_label',
            'smpl_workflow_lines_fk'              => 'smpl_label',
            'smpl_workflow_step_fk'               => 'smpl_label',
            'smpl_workflow_step_batch_fk'         => 'smpl_label',
            'smpl_workflow_step_goto_fk'          => 'smpl_label',
            'smpl_event_type_fk'                  => 'smpl_label',
            'smpl_sample_status_fk'               => 'smpl_label',
            'smpl_workflow_step_status_change_fk' => 'smpl_label',
            'smpl_kit_status_fk'                  => 'smpl_label',
            'smpl_case_type_fk'                   => 'smpl_label',
            'smpl_case_type_workflow_fk'          => 'smpl_label',
            'smpl_container_type_fk'              => 'smpl_label',
            'smpl_sample_type_fk'                 => 'smpl_label',
            // → smpl_id
            'smpl_subject_fk'                     => 'smpl_id',
            'smpl_case_fk'                        => 'smpl_id',
            'smpl_kit_fk'                         => 'smpl_id',
            'smpl_sample_fk'                      => 'smpl_id',
            'smpl_samples_fk'                     => 'smpl_id',
            // → smpl_id_generator_mask
            'smpl_id_gen_fk'                      => 'smpl_id_generator_mask',
            'smpl_subject_id_gen_fk'              => 'smpl_id_generator_mask',
            'smpl_case_id_gen_fk'                 => 'smpl_id_generator_mask',
            'smpl_kit_id_gen_fk'                  => 'smpl_id_generator_mask',
            // → smpl_event_start_time
            'smpl_events_fk'                      => 'smpl_event_start_time',
            'smpl_first_reception'                => 'smpl_event_start_time',
            'smpl_last_reception'                 => 'smpl_event_start_time',
            'smpl_first_transportation'           => 'smpl_event_start_time',
            'smpl_last_transportation'            => 'smpl_event_start_time',
            'smpl_first_storage'                  => 'smpl_event_start_time',
            'smpl_last_storage'                   => 'smpl_event_start_time',
            'smpl_first_centrifugation'           => 'smpl_event_start_time',
            'smpl_last_centrifugation'            => 'smpl_event_start_time',
            'smpl_first_analysis'                 => 'smpl_event_start_time',
            'smpl_last_analysis'                  => 'smpl_event_start_time',
            'smpl_first_processing'               => 'smpl_event_start_time',
            'smpl_last_processing'                => 'smpl_event_start_time',
        ];

        foreach ($mappings as $fieldName => $shownFieldName) {
            $fieldId      = DB::table('Field')->where('name', $fieldName)->value('id');
            $shownFieldId = DB::table('Field')->where('name', $shownFieldName)->value('id');
            if ($fieldId && $shownFieldId) {
                DB::table('ForeignChoiceField')
                    ->where('id', $fieldId)
                    ->update(['shown_field_id' => $shownFieldId]);
                Log::info("[SMPL] ForeignChoiceField shown_field updated: $fieldName → $shownFieldName");
            }
        }
    }

    private function updateShownFields(): void
    {
        // After all fields are attached, point each entity type's shown_field_id
        // to a meaningful field so entities display their label/id instead of a number.
        $mappings = [
            'SMPL_STUDY'          => 'smpl_label',
            'SMPL_STATUS'         => 'smpl_label',
            'SMPL_KIT_STATUS'     => 'smpl_label',
            'SMPL_EVENT_TYPE'     => 'smpl_label',
            'SMPL_ID_GENERATOR'   => 'smpl_id_generator_mask',
            'SMPL_WORKFLOW'       => 'smpl_label',
            'SMPL_WORKFLOW_LINE'  => 'smpl_label',
            'SMPL_WORKFLOW_STEP'  => 'smpl_label',
            'SMPL_CASE_TYPE'      => 'smpl_label',
            'SMPL_SUBJECT'        => 'smpl_id',
            'SMPL_CASE'           => 'smpl_id',
            'SMPL_KIT'            => 'smpl_id',
            'SMPL_SAMPLE'         => 'smpl_id',
            'SMPL_EVENT'          => 'smpl_event_start_time',
            'SMPL_CREATION'       => 'smpl_workflow_line_fk',
            'SMPL_SAMPLE_TYPE'    => 'smpl_label',
            'SMPL_CONTAINER_TYPE' => 'smpl_label',
        ];

        foreach ($mappings as $etName => $fieldName) {
            $etId    = DB::table('EntityType')->where('name', $etName)->value('id');
            $fieldId = DB::table('Field')->where('name', $fieldName)->value('id');
            if ($etId && $fieldId) {
                DB::table('EntityType')->where('id', $etId)->update(['shown_field_id' => $fieldId]);
                Log::info("[SMPL] shown_field_id updated: $etName → $fieldName");
            }
        }
    }
}
