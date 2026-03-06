import { NextRequest, NextResponse } from 'next/server'
import { requireAdminAuth } from '@/lib/auth-admin'
import { prisma } from '@/lib/db'
import { logAdminAction } from '@/lib/admin-logger'
import { getVMManager6RentalByHostId, renewVMManager6Rental } from '@/vm6/vmmanager6-rentals'

export async function POST(request: NextRequest) {
  const authError = requireAdminAuth(request)
  if (authError) return authError

  try {
    const body = await request.json()
    const { serverId, serverType, daysToAdd, reason } = body

    console.log('[Compensation] Request:', { serverId, serverType, daysToAdd, reason })

    if (!serverId || !serverType || !daysToAdd) {
      return NextResponse.json({ error: 'Missing required fields' }, { status: 400 })
    }

    const days = parseInt(daysToAdd)
    if (isNaN(days) || days < 1) {
      return NextResponse.json({ error: 'Invalid days amount' }, { status: 400 })
    }

    let server: any = null
    let userId: string | null = null

    switch (serverType) {
      case 'vds':
        // For VDS, serverId is vmManagerId (number)
        const vdsId = parseInt(serverId)
        console.log('[Compensation VDS] Parsed ID:', vdsId, 'isNumber:', !isNaN(vdsId))
        
        if (!isNaN(vdsId)) {
          // Get rental from local database
          const rental = getVMManager6RentalByHostId(vdsId)
          console.log('[Compensation VDS] Found rental:', rental ? 'YES' : 'NO')
          
          if (rental) {
            server = {
              id: rental.id,
              vmManagerId: rental.vmmanager6_host_id,
              expiresAt: rental.expires_at,
              status: rental.status.toUpperCase(),
              name: `VDS-${rental.vmmanager6_host_id}`
            }
            userId = rental.user_id
          }
        }
        break

      case 'minecraft':
        server = await prisma.server.findUnique({
          where: { id: serverId },
          include: { user: true }
        })
        if (server) userId = server.userId
        break

      case 'dedicated':
        server = await prisma.dedicatedServer.findUnique({
          where: { id: serverId },
          include: { user: true }
        })
        if (server) userId = server.userId
        break

      case 'domain':
        server = await prisma.domain.findUnique({
          where: { id: serverId },
          include: { user: true }
        })
        if (server) userId = server.userId
        break

      case 'storagebox':
        server = await prisma.storageBox.findUnique({
          where: { id: serverId },
          include: { user: true }
        })
        if (server) userId = server.userId
        break

      default:
        return NextResponse.json({ error: 'Invalid server type' }, { status: 400 })
    }

    if (!server) {
      console.log('[Compensation] Server not found:', { serverId, serverType })
      return NextResponse.json({ error: serverType + ' server not found' }, { status: 404 })
    }

    console.log('[Compensation] Server found:', { id: server.id, vmManagerId: server.vmManagerId, expiresAt: server.expiresAt })

    const currentExpiration = new Date(server.expiresAt)
    const newExpiration = new Date(currentExpiration)
    newExpiration.setDate(newExpiration.getDate() + days)

    let updatedServer: any = null

    switch (serverType) {
      case 'vds':
        // Renew VDS rental in local database
        const renewedRental = renewVMManager6Rental(server.id, days)
        if (renewedRental) {
          updatedServer = {
            id: renewedRental.id,
            vmManagerId: renewedRental.vmmanager6_host_id,
            expiresAt: renewedRental.expires_at,
            status: renewedRental.status.toUpperCase()
          }
        }
        break

      case 'minecraft':
        updatedServer = await prisma.server.update({
          where: { id: serverId },
          data: {
            expiresAt: newExpiration,
            status: server.status === 'SUSPENDED' ? 'ACTIVE' : server.status
          }
        })
        break

      case 'dedicated':
        updatedServer = await prisma.dedicatedServer.update({
          where: { id: serverId },
          data: {
            expiresAt: newExpiration,
            status: server.status === 'SUSPENDED' ? 'ACTIVE' : server.status
          }
        })
        break

      case 'domain':
        updatedServer = await prisma.domain.update({
          where: { id: serverId },
          data: {
            expiresAt: newExpiration,
            status: server.status === 'SUSPENDED' ? 'ACTIVE' : server.status
          }
        })
        break

      case 'storagebox':
        updatedServer = await prisma.storageBox.update({
          where: { id: serverId },
          data: {
            expiresAt: newExpiration,
            status: server.status === 'SUSPENDED' ? 'ACTIVE' : server.status
          }
        })
        break
    }

    await logAdminAction({
      action: 'BALANCE_ADD',
      description: 'Компенсация: +' + days + ' дней для ' + serverType.toUpperCase() + ' сервера' + (reason ? ', причина: ' + reason : ''),
      userId: userId || undefined,
      metadata: { serverId, serverType, daysAdded: days, reason, compensationType: 'days' }
    })

    return NextResponse.json({
      success: true,
      server: updatedServer,
      daysAdded: days,
      newExpiration: newExpiration.toISOString()
    })

  } catch (error) {
    console.error('[Compensation API] Error:', error)
    return NextResponse.json({ error: 'Internal server error' }, { status: 500 })
  }
}

export async function GET(request: NextRequest) {
  const authError = requireAdminAuth(request)
  if (authError) return authError

  try {
    const { searchParams } = new URL(request.url)
    const serverId = searchParams.get('serverId')
    const serverType = searchParams.get('serverType')

    if (!serverId || !serverType) {
      return NextResponse.json({ error: 'Missing serverId or serverType' }, { status: 400 })
    }

    const logs = await prisma.adminLog.findMany({
      where: {
        action: 'BALANCE_ADD',
        description: { contains: 'Компенсация' }
      },
      orderBy: { createdAt: 'desc' },
      take: 10
    })

    return NextResponse.json({ logs })

  } catch (error) {
    console.error('[Compensation API] Error:', error)
    return NextResponse.json({ error: 'Internal server error' }, { status: 500 })
  }
}