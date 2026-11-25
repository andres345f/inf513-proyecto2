import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

export const estadisticasApi = {
  negocio: (rango) => clienteHttp.get('/panel/negocio', { params: rango }).then(datos),
  accesos: (rango) => clienteHttp.get('/panel/accesos', { params: rango }).then(datos),
  bitacora: (filtros) => clienteHttp.get('/admin/bitacora', { params: filtros }).then(datos),
  /** Descarga un reporte CSV y devuelve { blob, nombre }. */
  reporte: (tipo, rango) => clienteHttp.get(`/reportes/${tipo}`, { params: rango, responseType: 'blob' }).then((r) => ({
    blob: r.data,
    nombre: /filename="?([^";]+)"?/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? `${tipo}.csv`,
  })),
}
