<!--
  - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps<{
	initialName: string
	onFinish: (value: string | null) => void
}>()

const open = ref(true)
const folderName = ref(props.initialName)
let completed = false

const valid = computed(() => {
	const name = folderName.value.trim()
	return name !== ''
		&& name.split('/').every((part) => part !== ''
			&& part !== '.'
			&& part !== '..'
			&& !part.includes('\\')
			&& [...part].every((character) => character.charCodeAt(0) >= 32 && character.charCodeAt(0) !== 127))
})

/**
 * Finish the dialog once, including when it is cancelled.
 *
 * @param value Confirmed path or null for cancel
 */
function close(value: string | null): void {
	if (completed) {
		return
	}
	completed = true
	open.value = false
	props.onFinish(value)
}

/**
 * Treat closing via the modal or Escape key as cancellation.
 *
 * @param value Whether the dialog is open
 */
function onOpenChange(value: boolean): void {
	if (!value) {
		close(null)
	}
}

/** Submit a validated destination from the action button or Enter. */
function submit(): void {
	if (valid.value) {
		close(folderName.value.trim())
	}
}
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('extract', 'Extract archive')"
		@update:open="onOpenChange">
		<p>{{ t('extract', 'Choose a folder name or a relative path inside the current directory.') }}</p>
		<NcTextField
			v-model="folderName"
			:label="t('extract', 'Destination folder')"
			:placeholder="t('extract', 'Folder name')"
			:error="!valid"
			:helperText="!valid ? t('extract', 'Enter a valid relative path without parent-directory components.') : undefined"
			required
			@keyup.enter="submit" />
		<template #actions>
			<NcButton @click="close(null)">
				{{ t('extract', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid"
				@click="submit">
				{{ t('extract', 'Extract') }}
			</NcButton>
		</template>
	</NcDialog>
</template>
