const GRUPOS = [
  ['Pagos y cuotas', ['/mis-pagos', '/planes-pago', '/metodos-pago']],
  ['Producción', ['/productor/producciones', '/productor/lotes', '/productor/propuestas']],
  ['Ventas a FEDER', ['/productor/ventas', '/productor/indicadores']],
  ['Venta y posventa', ['/gestion/pedidos', '/gestion/pagos', '/gestion/entregas', '/gestion/devoluciones']],
  ['Catálogo de FEDER', ['/gestion/productos', '/gestion/categorias']],
  ['Abastecimiento', ['/gestion/productores', '/gestion/comunidades', '/gestion/producciones', '/gestion/compras', '/gestion/inventario']],
  ['Seguimiento', ['/panel', '/indicadores', '/reportes']],
  ['Cuentas y permisos', ['/admin/usuarios', '/admin/permisos']],
  ['Auditoría', ['/admin/bitacora', '/admin/accesos']],
]

const ETIQUETAS = { '/mi-cuenta': 'Resumen', '/gestion/pedidos': 'Gestión de pedidos', '/gestion/pagos': 'Cobros' }
const COMUNES = new Set(['/', '/catalogo', '/perfil', '/notificaciones'])

export function etiquetaTarea(entrada) {
  return ETIQUETAS[entrada.ruta] ?? entrada.etiqueta
}

/** La API decide qué existe. La interfaz solo ordena y agrupa sus entradas. */
export function organizarMenu(entradas) {
  const bloques = new Map()
  for (const entrada of entradas) {
    if (COMUNES.has(entrada.ruta)) continue
    const grupo = GRUPOS.find(([, rutas]) => rutas.includes(entrada.ruta))
    const clave = grupo?.[0] ?? entrada.ruta
    if (!bloques.has(clave)) bloques.set(clave, { titulo: grupo?.[0], entradas: [] })
    bloques.get(clave).entradas.push(entrada)
  }
  return Array.from(bloques.values())
}
