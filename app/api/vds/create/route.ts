import { NextRequest, NextResponse } from "next/server"
import { getAuthUser } from "@/lib/auth"
import { prisma } from "@/lib/db"
import { getVmManager } from "@/vm6/VmManager"
import { getVMManager6API } from "@/vm6/vmmanager6"
import { sendVdsCreatedEmail, sendVmManagerAccountEmail } from "@/lib/email"
import { getNodeSelectionService, NodeSelectionOptions } from "@/lib/node-selection"

// Discord webhook - уведомление о созданной VM
async function sendDiscordNotification(data: {
  userName: string
  userEmail: string
  planName: string
  planPrice: number
  osName: string
  serverName: string
  vmId: number
  ipAddress?: string
}) {
  try {
    const webhookUrl = await prisma.adminSettings.findUnique({
      where: { key: 'discordWebhook' }
    })
    
    if (!webhookUrl?.value) return

    await fetch(webhookUrl.value, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify({
        content: '@everyone',
        embeds: [{
          title: 'New VDS Created',
          color: 0x10B981,
          fields: [
            { name: 'User', value: `${data.userName || 'No name'}\n${data.userEmail}`, inline: true },
            { name: 'Plan', value: `${data.planName}\n${data.planPrice} RUB`, inline: true },
            { name: 'OS', value: data.osName, inline: true },
            { name: 'Server Name', value: data.serverName, inline: true },
            { name: 'VM ID', value: String(data.vmId), inline: true },
            { name: 'IP', value: data.ipAddress || 'Назначается...', inline: true },
          ],
          timestamp: new Date().toISOString()
        }]
      })
    })
  } catch (e) {
    console.error('[Discord Webhook] Error:', e)
  }
}

// Фоновая задача для ожидания готовности VM и отправки email
async function waitForVmAndSendEmail(
  vmId: number,
  userEmail: string,
  serverName: string,
  osName: string,
  password: string,
  userName: string,
  planName: string,
  planPrice: number,
  planCpu: number,
  planRamMib: number,
  planDiskMib: number
) {
  const vmAPI = getVMManager6API()
  const panelUrl = process.env.VMMANAGER6_PANEL_URL || 'https://vmmanager.space'
  const maxAttempts = 60 // 5 минут (60 * 5 сек)
  const pollInterval = 5000 // 5 секунд
  
  console.log(`[VDS Email] Starting to wait for VM ${vmId} to be ready...`)
  
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      const host = await vmAPI.getVm(vmId)
      
      // Получаем IP адреса (IPv4 и IPv6)
      let ipv4Address: string | null = null
      let ipv6Address: string | null = null
      
      // Проверяем ip4 массив
      if (Array.isArray(host.ip4) && host.ip4.length > 0) {
        const ip = host.ip4[0]
        ipv4Address = (ip as any).ip || (ip as any).ip_addr || (ip as any).name || null
      }
      
      // Проверяем ip6 массив
      if (Array.isArray(host.ip6) && host.ip6.length > 0) {
        const ip = host.ip6[0]
        ipv6Address = (ip as any).ip || (ip as any).ip_addr || (ip as any).name || null
      }
      
      // Если нет в ip4, пробуем отдельный запрос
      if (!ipv4Address) {
        try {
          const ipv4List = await vmAPI.getVmIPv4(vmId)
          if (ipv4List.length > 0) {
            ipv4Address = (ipv4List[0] as any).ip || (ipv4List[0] as any).ip_addr || ipv4List[0].name || null
          }
        } catch {}
      }
      
      // Если нет в ip6, пробуем отдельный запрос
      if (!ipv6Address) {
        try {
          const ipv6List = await vmAPI.getVmIPv6(vmId)
          if (ipv6List.length > 0) {
            ipv6Address = (ipv6List[0] as any).ip || (ipv6List[0] as any).ip_addr || ipv6List[0].name || null
          }
        } catch {}
      }
      
      // Формируем строку с IP адресами
      const ipAddress = [ipv4Address, ipv6Address].filter(Boolean).join(', ') || null
      
      // Получаем характеристики из VMManager6 или используем данные плана
      const cpu = host.cpu_number || host.cpu || planCpu
      const ram = host.ram_mib || host.ram || planRamMib
      
      let disk = 0
      if (typeof host.disk === 'object' && host.disk) {
        disk = (host.disk as any).disk_mib || (host.disk as any).size_mib || 0
      } else if (host.disk_info) {
        disk = host.disk_info.disk_mib || host.disk_info.size_mib || 0
      }
      // Если диск не получен из VMManager6, используем данные плана
      if (disk === 0) {
        disk = planDiskMib
      }
      
      // Проверяем готовность: есть хотя бы один IP (IPv4 или IPv6) и статус active
      const isReady = (ipv4Address || ipv6Address) && (host.state === 'active' || host.state === 'running')
      
      console.log(`[VDS Email] Attempt ${attempt + 1}: state=${host.state}, ipv4=${ipv4Address}, ipv6=${ipv6Address}, cpu=${cpu}, ram=${ram}, disk=${disk}`)
      
      if (isReady) {
        console.log(`[VDS Email] VM ${vmId} is ready! Sending email to ${userEmail}`)
        
        // Отправляем email
        await sendVdsCreatedEmail(userEmail, {
          serverName,
          ipAddress,
          osName,
          password,
          ram: Math.round(ram / 1024), // MiB to GB
          cpu,
          disk: Math.round(disk / 1024), // MiB to GB
          panelUrl: `${panelUrl}/vm/host/${vmId}`
        })
        
        // Отправляем Discord с IP
        await sendDiscordNotification({
          userName,
          userEmail,
          planName,
          planPrice,
          osName,
          serverName,
          vmId,
          ipAddress: ipAddress || undefined
        })
        
        console.log(`[VDS Email] Email sent successfully for VM ${vmId}`)
        return
      }
      
    } catch (error) {
      console.error(`[VDS Email] Error checking VM ${vmId}:`, error)
    }
    
    // Ждём перед следующей попыткой
    await new Promise(resolve => setTimeout(resolve, pollInterval))
  }
  
  console.error(`[VDS Email] Timeout waiting for VM ${vmId} to be ready`)
}

// POST - создать VDS сервер
export async function POST(request: NextRequest) {
  try {
    const user = await getAuthUser(request)
    if (!user) {
      return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
    }

    const body = await request.json()
    const { planId, osId, name, password, ipv6Enabled, ipv6Prefix } = body

    // Валидация
    if (!planId || !osId || !name || !password) {
      return NextResponse.json({ error: "Missing required fields" }, { status: 400 })
    }

    if (password.length < 8) {
      return NextResponse.json({ error: "Password must be at least 8 characters" }, { status: 400 })
    }

    if (name.length < 2 || name.length > 10) {
      return NextResponse.json({ error: "Name must be 2-10 characters" }, { status: 400 })
    }

    // Получаем план
    const plan = await prisma.plan.findUnique({
      where: { id: planId }
    })

    if (!plan || plan.category !== "VDS") {
      return NextResponse.json({ error: "Plan not found" }, { status: 404 })
    }

    // Автоматически включаем IPv6, если в плане указан IPv6 пул
    const shouldEnableIpv6 = ipv6Enabled || (plan.vmIpv6PoolId !== null && plan.vmIpv6PoolId !== undefined)
    
    console.log('[VDS Create] IPv6 settings:', {
      userRequested: ipv6Enabled,
      planHasIpv6Pool: plan.vmIpv6PoolId,
      willEnable: shouldEnableIpv6
    })

    // Проверяем OS
    const osImage = await prisma.vdsOsImage.findFirst({
      where: { vmManagerId: osId, isActive: true }
    })

    if (!osImage) {
      return NextResponse.json({ error: "OS not available" }, { status: 400 })
    }

    // Получаем пользователя из БД
    const dbUser = await prisma.user.findUnique({
      where: { id: user.id }
    })

    if (!dbUser) {
      return NextResponse.json({ error: "User not found" }, { status: 404 })
    }

    // Проверяем баланс
    if (dbUser.balance < plan.price) {
      return NextResponse.json({ error: "Insufficient balance" }, { status: 400 })
    }

    // Создаём VDS через VMManager
    const vmManager = getVmManager()
    
    // Для VDS планов с vmPresetId используем VMManager
    // Для кастомных планов (RU-1, DE-1) создаём запись в БД без VMManager
    const isCustomPlan = !plan.vmPresetId && plan.vdsCustomSpecs
    
    if (!isCustomPlan && !plan.vmPresetId) {
      return NextResponse.json({ 
        error: "Plan is not configured for VDS (missing vmPresetId or custom specs)" 
      }, { status: 400 })
    }

    // Если это кастомный план, создаём только запись в БД
    if (isCustomPlan) {
      try {
        // Списываем баланс
        await prisma.user.update({
          where: { id: user.id },
          data: { balance: { decrement: plan.price } }
        })

        // Создаём запись VDS сервера
        const expiresAt = new Date()
        expiresAt.setMonth(expiresAt.getMonth() + 1)

        const vdsServer = await prisma.vdsServer.create({
          data: {
            name,
            userId: user.id,
            planId: plan.id,
            status: 'PENDING',
            ipAddress: null,
            rootPassword: password,
            expiresAt,
            paidAmount: plan.price
          }
        })

        // Создаём транзакцию
        await prisma.transaction.create({
          data: {
            userId: user.id,
            type: 'VDS_PAYMENT',
            amount: -plan.price,
            description: `Создание VDS сервера: ${name}`,
            serverId: vdsServer.id,
            status: 'COMPLETED',
            method: 'MANUAL'
          }
        })

        // Отправляем уведомление на email
        try {
          const { sendVdsCreatedEmail } = await import('@/lib/email')
          const customSpecs = JSON.parse(plan.vdsCustomSpecs || '{}')
          await sendVdsCreatedEmail(user.email, {
            serverName: name,
            ipAddress: 'Назначается...',
            osName: 'Custom VDS',
            password,
            ram: Math.round(plan.ram / 1024),
            cpu: plan.cpu,
            disk: Math.round(plan.disk / 1024),
            panelUrl: '#'
          })
        } catch (emailError) {
          console.error('[VDS Create] Email error:', emailError)
        }

        return NextResponse.json({
          success: true,
          server: {
            id: vdsServer.id,
            name: vdsServer.name,
            status: vdsServer.status
          }
        })
      } catch (error) {
        console.error('[VDS Create] Custom plan error:', error)
        return NextResponse.json({ 
          error: 'Failed to create VDS server' 
        }, { status: 500 })
      }
    }

    // Получаем cluster и node для VMManager планов с использованием умного выбора
    let clusterId: number | undefined = plan.vmClusterId || undefined
    let nodeId: number | undefined
    let validIpPools: number[] = []

    console.log('[VDS Create] 🎯 Starting node selection for plan:', {
      planId: plan.id,
      planName: plan.name,
      vmClusterId: plan.vmClusterId,
      vmNodeId: plan.vmNodeId,
      vmNodeStrategy: plan.vmNodeStrategy,
      vmPresetId: plan.vmPresetId
    })

    try {
      const nodeSelectionService = getNodeSelectionService()
      
      // Подготавливаем опции для выбора ноды
      const selectionOptions: NodeSelectionOptions = {
        clusterId: clusterId,
        strategy: plan.vmNodeStrategy as any || 'auto',
        specificNodeId: plan.vmNodeId || undefined,
        requireActiveOnly: true
      }

      console.log('[VDS Create] 📋 Node selection options:', selectionOptions)

      // Выбираем оптимальную ноду
      const selectionResult = await nodeSelectionService.selectNode(selectionOptions)
      
      nodeId = selectionResult.nodeId
      clusterId = selectionResult.clusterId
      validIpPools = selectionResult.validIpPools
      
      console.log('[VDS Create] ✅ Node selection result:', {
        nodeId,
        clusterId,
        validIpPools,
        reason: selectionResult.selectionReason,
        healthScore: selectionResult.healthScore,
        loadScore: selectionResult.loadScore
      })

      // Дополнительная валидация совместимости
      const isCompatible = await nodeSelectionService.validateNodeClusterCompatibility(nodeId, clusterId)
      if (!isCompatible) {
        throw new Error(`Selected node ${nodeId} is not compatible with cluster ${clusterId}`)
      }
      
      console.log('[VDS Create] ✅ Node-cluster compatibility validated')

    } catch (error) {
      console.error('[VDS Create] ❌ Node selection error:', error)
      console.error('[VDS Create] Error stack:', error instanceof Error ? error.stack : 'No stack')
      return NextResponse.json({ 
        error: `Ошибка выбора сервера: ${error instanceof Error ? error.message : 'Неизвестная ошибка'}` 
      }, { status: 500 })
    }

    // Получаем IP пул для кластера с валидацией
    let ipPoolIds: number[] | undefined
    let ipv6PoolIds: number[] | undefined
    
    try {
      // Используем валидированные пулы из node selection service
      if (validIpPools.length > 0) {
        // Получаем информацию о пулах для определения IPv4/IPv6
        const vmAPI = getVMManager6API()
        const allPools = await vmAPI.getIPPools()
        
        // Разделяем пулы на IPv4 и IPv6
        const ipv4Pools = validIpPools.filter(poolId => {
          const pool = allPools.find(p => p.id === poolId)
          return pool && pool.family === 'ipv4'
        })
        
        const ipv6Pools = validIpPools.filter(poolId => {
          const pool = allPools.find(p => p.id === poolId)
          return pool && pool.family === 'ipv6'
        })
        
        console.log('[VDS Create] Available pools:', {
          ipv4Pools,
          ipv6Pools,
          ipv6Requested: shouldEnableIpv6,
          note: 'VMManager6 auto-selects IPv6 pool from cluster'
        })
        
        // Выбираем IPv4 пул
        if (ipv4Pools.length > 0) {
          // Для кластера 9 (RU) приоритет пулу 5, если он доступен
          if (clusterId === 9 && ipv4Pools.includes(5)) {
            ipPoolIds = [5]
            console.log('[VDS Create] Cluster 9 (RU) - using validated IPv4 pool 5')
          } else if (plan.vmIpPoolId && ipv4Pools.includes(plan.vmIpPoolId)) {
            // Используем пул из плана, если он валиден для кластера
            ipPoolIds = [plan.vmIpPoolId]
            console.log('[VDS Create] Using validated IPv4 pool from plan:', plan.vmIpPoolId)
          } else {
            // Используем первый доступный валидный IPv4 пул
            ipPoolIds = [ipv4Pools[0]]
            console.log('[VDS Create] Using first validated IPv4 pool:', ipv4Pools[0])
          }
        }
        
        // Выбираем IPv6 пул, если включен IPv6 или если в плане указан IPv6 пул
        if (shouldEnableIpv6 && ipv6Pools.length > 0) {
          // Приоритет 1: пул из настроек плана, если он валиден
          if (plan.vmIpv6PoolId && ipv6Pools.includes(plan.vmIpv6PoolId)) {
            // Преобразуем виртуальный ID обратно в реальный ID пула
            const realPoolId = Math.floor(plan.vmIpv6PoolId / 10000)
            ipv6PoolIds = [realPoolId]
            console.log('[VDS Create] Using validated IPv6 pool from plan:', plan.vmIpv6PoolId, '-> real ID:', realPoolId)
          } else {
            // Приоритет 2: первый доступный валидный IPv6 пул
            const realPoolId = Math.floor(ipv6Pools[0] / 10000)
            ipv6PoolIds = [realPoolId]
            console.log('[VDS Create] Using first validated IPv6 pool:', ipv6Pools[0], '-> real ID:', realPoolId)
          }
        } else if (shouldEnableIpv6 && ipv6Pools.length === 0) {
          console.warn('[VDS Create] ⚠️ IPv6 requested but no IPv6 pools available for cluster', clusterId)
          // Не отключаем IPv6, пусть VMManager6 попробует выдать автоматически
        }
      } else {
        // Fallback: пусть VMManager6 выбирает сам
        ipPoolIds = undefined
        ipv6PoolIds = undefined
        console.log('[VDS Create] No validated pools found, VMManager6 will auto-select')
      }
    } catch (error) {
      console.error('[VDS Create] Error selecting IP pool:', error)
      // Fallback: пусть VMManager6 выбирает сам
      ipPoolIds = undefined
      ipv6PoolIds = undefined
      console.log('[VDS Create] IP pool selection failed, VMManager6 will auto-select')
    }

    console.log('[VDS Create] 🚀 Final VM creation params:', {
      name, 
      osId, 
      preset: plan.vmPresetId,
      cluster: clusterId, 
      node: nodeId,
      ipv4Pool: ipPoolIds, 
      ipv4Number: 1,
      ipv6Enabled: shouldEnableIpv6,
      ipv6Prefix: shouldEnableIpv6 ? (ipv6Prefix || 64) : undefined,
      note: 'VMManager6 auto-selects IPv6 pool from cluster',
      validatedPools: validIpPools,
      userEmail: dbUser.email
    })

    // Финальная проверка совместимости перед созданием VM
    if (clusterId && nodeId) {
      const nodeSelectionService = getNodeSelectionService()
      console.log('[VDS Create] 🔍 Running final compatibility check...')
      const finalCompatibilityCheck = await nodeSelectionService.validateNodeClusterCompatibility(nodeId, clusterId)
      if (!finalCompatibilityCheck) {
        console.error('[VDS Create] ❌ Final compatibility check failed:', { nodeId, clusterId })
        return NextResponse.json({ 
          error: `Критическая ошибка: выбранный сервер ${nodeId} не совместим с кластером ${clusterId}. Обратитесь в поддержку.` 
        }, { status: 500 })
      }
      console.log('[VDS Create] ✅ Final compatibility check passed')
    } else {
      console.warn('[VDS Create] ⚠️ Skipping final compatibility check - missing cluster or node ID')
    }

    const { vm, rental, vmAccountPassword, isFirstVds } = await vmManager.createVmWithRental(
      dbUser.id,
      {
        name,
        osId,
        password,
        preset: plan.vmPresetId || undefined,
        cluster: clusterId,
        node: nodeId,
        ipv4Pool: ipPoolIds,
        ipv4Number: 1,
        // IPv6 - VMManager6 автоматически выбирает пул из доступных для кластера
        ipv6Enabled: shouldEnableIpv6,
        ipv6Prefix: shouldEnableIpv6 ? (ipv6Prefix || 64) : undefined,
      },
      {
        planName: plan.name,
        price: plan.price,
        days: 30,
        autoRenew: true
      },
      dbUser.email
    ).catch(error => {
      console.error('[VDS Create] VM creation failed:', error)
      
      // Проверяем специфичные ошибки VMManager6
      const errorMessage = error.message || error.toString()
      
      if (errorMessage.includes('Selected pools are not connected to the cluster')) {
        throw new Error(`Ошибка конфигурации: IP пулы не подключены к кластеру ${clusterId}. Обратитесь в поддержку.`)
      } else if (errorMessage.includes('node') && errorMessage.includes('cluster')) {
        throw new Error(`Ошибка совместимости: сервер ${nodeId} не совместим с кластером ${clusterId}. Обратитесь в поддержку.`)
      } else if (errorMessage.includes('insufficient resources')) {
        throw new Error('Недостаточно ресурсов на выбранном сервере. Попробуйте позже.')
      } else if (errorMessage.includes('preset')) {
        throw new Error(`Ошибка конфигурации плана: пресет ${plan.vmPresetId} недоступен.`)
      } else {
        throw new Error(`Ошибка создания VDS: ${errorMessage}`)
      }
    })

    // Если это первая покупка VDS, отправляем данные VmManager аккаунта
    if (isFirstVds) {
      console.log('[VDS Create] First VDS purchase detected, sending VmManager credentials')
      await sendVmManagerAccountEmail(dbUser.email, {
        vmEmail: dbUser.email,
        vmPassword: vmAccountPassword
      }).catch(err => {
        console.error('[VDS Create] Failed to send VmManager credentials email:', err)
      })
    }

    // Списываем баланс
    await prisma.user.update({
      where: { id: dbUser.id },
      data: { balance: { decrement: plan.price } }
    })

    // Записываем транзакцию
    await prisma.transaction.create({
      data: {
        userId: dbUser.id,
        amount: -plan.price,
        type: "PAYMENT",
        description: `VDS: ${name} (${plan.name})`,
        status: "COMPLETED"
      }
    })

    // Получаем данные пресета для характеристик
    let planCpu = 1
    let planRamMib = 1024
    let planDiskMib = 10240
    
    try {
      const vmAPI = getVMManager6API()
      const preset = await vmAPI.getPreset(plan.vmPresetId!)
      planCpu = preset.cpu_number || 1
      planRamMib = preset.ram_mib || 1024
      planDiskMib = preset.disks?.reduce((sum, disk) => sum + (disk.size_mib || 0), 0) || 10240
      console.log('[VDS Create] Preset specs:', { cpu: planCpu, ram: planRamMib, disk: planDiskMib })
    } catch (e) {
      console.warn('[VDS Create] Could not get preset specs:', e)
    }

    // Запускаем фоновую задачу для ожидания готовности VM и отправки email/Discord
    // Не ждём завершения - задача выполнится асинхронно
    waitForVmAndSendEmail(
      vm.id,
      dbUser.email,
      name,
      osImage.name,
      password,
      dbUser.name || '',
      plan.name,
      plan.price,
      planCpu,
      planRamMib,
      planDiskMib
    ).catch(err => console.error('[VDS Email] Background task error:', err))

    // Возвращаем результат сразу - VM в процессе установки
    // Email будет отправлен когда VM получит IP
    return NextResponse.json({
      success: true,
      message: 'VDS создан и устанавливается. Данные придут на почту после готовности.',
      server: {
        id: vm.id,
        name: vm.name,
        status: vm.status || 'installing',
        ipAddresses: vm.ip_addresses || [],
        rental
      }
    })

  } catch (error) {
    console.error("[VDS Create] Error:", error)
    return NextResponse.json(
      { error: error instanceof Error ? error.message : "Error creating VDS" },
      { status: 500 }
    )
  }
}
