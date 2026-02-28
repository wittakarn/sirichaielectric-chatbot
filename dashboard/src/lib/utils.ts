import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatDate(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

export function formatTime(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleTimeString('th-TH', {
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function truncate(str: string, max: number): string {
  if (str.length <= max) return str
  return str.slice(0, max) + '...'
}
