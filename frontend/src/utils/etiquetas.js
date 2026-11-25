// Textos legibles para los códigos que vienen de la base de datos (todo lo visible en español).

export const ESTADOS_PEDIDO = {
  PENDIENTE: 'Pendiente de pago',
  ESPERANDO_ABASTECIMIENTO: 'Esperando abastecimiento',
  CONFIRMADO: 'Confirmado',
  EN_PREPARACION: 'En preparación',
  DESPACHADO: 'Despachado',
  ENTREGADO: 'Entregado',
  CANCELADO: 'Cancelado',
}

export const ESTADOS_PAGO = { PENDIENTE: 'Pendiente', PAGADO: 'Pagado', RECHAZADO: 'Rechazado' }

export const ESTADOS_PLAN = { ACTIVO: 'Activo', COMPLETADO: 'Completado', CANCELADO: 'Cancelado', CERRADO_POR_DEVOLUCION: 'Cerrado por devolución' }

export function etiquetaCuota(cuota) {
  if (cuota.estado === 'ANULADA') return 'Anulada por devolución'
  if (cuota.estado === 'PAGADA') return 'Pagada'
  return cuota.vencida ? 'Vencida' : 'Pendiente'
}

export const METODOS_PAGO = { QR: 'QR', TARJETA: 'Tarjeta', EFECTIVO: 'Efectivo' }

export const ESTADOS_DEVOLUCION = { SOLICITADA: 'Solicitada', APROBADA: 'Aprobada', RECHAZADA: 'Rechazada', COMPLETADA: 'Completada', ANULADA: 'Anulada' }

export const RESOLUCIONES = { REPOSICION: 'Reposición del producto', REEMBOLSO: 'Reintegro del dinero' }

export const TIPOS_PEDIDO = { INMEDIATO: 'Compra inmediata', ENCARGO: 'Encargo' }

export const MODALIDADES = { RECOJO: 'Recojo en almacén', DELIVERY: 'Envío a domicilio' }

export const ESTADOS_ENTREGA = {
  PENDIENTE: 'Pendiente',
  LISTA_PARA_RECOJO: 'Lista para recoger',
  EN_CAMINO: 'En camino',
  ENTREGADA: 'Entregada',
  FALLIDA: 'Fallida',
  RETORNADA: 'Retornada',
  NO_RECUPERADA: 'No recuperada',
  CANCELADA: 'Cancelada',
}

export const UNIDADES = {
  KILOGRAMO: { corta: 'kg', larga: 'kilogramo' },
  LIBRA: { corta: 'lb', larga: 'libra' },
  ARROBA: { corta: '@', larga: 'arroba' },
  LITRO: { corta: 'L', larga: 'litro' },
  UNIDAD: { corta: 'u.', larga: 'unidad' },
}

/** Tono visual de la insignia según el estado (se traduce a una clase CSS). */
export function tonoEstado(estado) {
  if (['ENTREGADO', 'PAGADO', 'COMPLETADO', 'CONFIRMADO', 'DISPONIBLE'].includes(estado)) return 'exito'
  if (['CANCELADO', 'RECHAZADO', 'RECHAZADA', 'ANULADA', 'AGOTADO'].includes(estado)) return 'error'
  if (['PENDIENTE', 'SOLICITADA', 'ESPERANDO_ABASTECIMIENTO', 'POR_ENCARGO'].includes(estado)) return 'aviso'
  return 'info'
}

export function cantidadConUnidad(cantidad, unidad) {
  const numero = new Intl.NumberFormat('es-BO', { maximumFractionDigits: 3 }).format(cantidad)
  return `${numero} ${UNIDADES[unidad]?.corta ?? ''}`.trim()
}
