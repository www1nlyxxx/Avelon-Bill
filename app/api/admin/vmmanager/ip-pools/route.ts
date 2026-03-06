/**
 * VMManager IP Pools API
 * Управление IP пулами для VDS
 */

import { NextRequest, NextResponse } from 'next/server'
import { requireAdminAuth } from '@/lib/auth-admin'
import { getVmManager } from '@/vm6/VmManager'

// GET - получить список IP пулов из VMManager6
export async function GET(request: NextRequest) {
  const authError = await requireAdminAuth(request)
  if (authError) return authError

  try {
    const vm = getVmManager()
    const ipPools = await vm.getIPPools()
    
    console.log('[Admin VMManager] Fetched IP pools:', ipPools.length)
    console.log('[Admin VMManager] IPv4 pools:', ipPools.filter(p => p.family === 'ipv4').length)
    console.log('[Admin VMManager] IPv6 pools:', ipPools.filter(p => p.family === 'ipv6').length)
    console.log('[Admin VMManager] Sample pool:', ipPools[0])
    
    return NextResponse.json(ipPools)
  } catch (error) {
    console.error('[Admin VMManager] Error fetching IP pools:', error)
    return NextResponse.json(
      { error: error instanceof Error ? error.message : 'Failed to fetch IP pools' },
      { status: 500 }
    )
  }
}
