/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { delay } from '../utils.js'

describe('delay', () => {
	beforeEach(() => vi.useFakeTimers())
	afterEach(() => vi.useRealTimers())

	it('calls back only once the time has passed', () => {
		const cb = vi.fn()
		delay(cb, 200)()

		vi.advanceTimersByTime(199)
		expect(cb).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(cb).toHaveBeenCalledOnce()
	})

	it('keeps only the last call of a burst, which is what the settings pages rely on', () => {
		const cb = vi.fn()
		const delayed = delay(cb, 100)

		delayed('first')
		vi.advanceTimersByTime(50)
		delayed('second')
		vi.advanceTimersByTime(50)
		expect(cb).not.toHaveBeenCalled()

		vi.advanceTimersByTime(50)
		expect(cb).toHaveBeenCalledOnce()
		expect(cb).toHaveBeenCalledWith('second')
	})

	it('treats a missing delay as no delay', () => {
		const cb = vi.fn()
		delay(cb)()
		vi.advanceTimersByTime(0)
		expect(cb).toHaveBeenCalledOnce()
	})
})
