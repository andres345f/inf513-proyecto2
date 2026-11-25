import { useCallback } from 'react'

import { useSearchParams } from 'react-router-dom'

import { estadisticasApi } from '../../api/estadisticasApi'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { formatearFechaHora } from '../../utils/formato'

const ACCIONES = {
  INICIO_SESION: 'Inicio de sesión', CREAR: 'Crear', MODIFICAR: 'Modificar', ANULAR: 'Anular', ASIGNAR_ROL: 'Asignar rol',
  REVOCAR_ROL: 'Revocar rol', CAMBIO_PRECIO: 'Cambio de precio', APROBAR_SOLICITUD: 'Aprobar solicitud',
  RECHAZAR_SOLICITUD: 'Rechazar solicitud', INVITAR: 'Invitar',
}

const MOTIVOS = {
  CORREO_NO_REGISTRADO: 'correo no registrado', CONTRASENA_INCORRECTA: 'contraseña incorrecta',
  CUENTA_INACTIVA: 'cuenta inactiva', SIN_ROL_VIGENTE: 'sin rol vigente',
}

/** Resumen legible del detalle JSON de un registro. */
function describir(r) {
  const d = r.detalle || {}
  if (r.accion === 'INICIO_SESION') {
    return d.resultado === 'EXITO' ? `Ingreso como ${d.rol ?? '—'}` : `Fallido (${MOTIVOS[d.motivo] ?? d.motivo ?? '—'}) · ${d.correo ?? ''}`
  }
  if (r.entidad === 'ACCESO_WEB') return `Visitó ${d.pagina}`
  if (d.estado_anterior || d.estado_nuevo) return `${d.estado_anterior ?? '—'} → ${d.estado_nuevo ?? '—'}`
  return Object.entries(d).slice(0, 3).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(' · ')
}

const COLUMNAS = [
  { clave: 'fecha', etiqueta: 'Fecha', alRender: (r) => formatearFechaHora(r.fecha) },
  { clave: 'usuario', etiqueta: 'Usuario', alRender: (r) => r.usuario ?? 'Visitante' },
  {
    clave: 'accion', etiqueta: 'Acción',
    alRender: (r) => r.accion === 'INICIO_SESION'
      ? <Insignia tono={r.detalle?.resultado === 'EXITO' ? 'exito' : 'error'}>{ACCIONES[r.accion]}</Insignia>
      : ACCIONES[r.accion] ?? r.accion,
  },
  { clave: 'entidad', etiqueta: 'Entidad', alRender: (r) => <>{r.entidad === 'ACCESO_WEB' ? 'Página web' : r.entidad}{r.entidad_id ? ` #${r.entidad_id}` : ''}</> },
  { clave: 'detalle', etiqueta: 'Detalle', clase: 'celda-detalle', alRender: (r) => describir(r) },
  { clave: 'ip', etiqueta: 'IP', alRender: (r) => r.ip ?? '—' },
]

/** Bitácora de auditoría con filtros del lado del servidor (solo administrador). */
export default function BitacoraPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const bitacora = useCargaApi(() => estadisticasApi.bitacora(Object.fromEntries(parametros)), [clave])

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const valor = (c) => parametros.get(c) || ''
  const cambiar = (e) => filtrar({ [e.target.name]: e.target.value })
  const alSalir = (e) => e.target.value !== valor(e.target.name) && filtrar({ [e.target.name]: e.target.value })

  const filtros = (
    <>
      <label>Usuario o correo<input name="usuario" defaultValue={valor('usuario')} maxLength={80} onBlur={alSalir}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Acción<select name="accion" value={valor('accion')} onChange={cambiar}>
        <option value="">Todas</option>
        {Object.entries(ACCIONES).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Resultado del ingreso<select name="resultado" value={valor('resultado')} onChange={cambiar}>
        <option value="">Todos</option><option value="EXITO">Correcto</option><option value="FALLIDO">Fallido</option>
      </select></label>
      <label>IP<input name="ip" defaultValue={valor('ip')} maxLength={45} onBlur={alSalir}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Desde<input type="date" name="desde" value={valor('desde')} onChange={cambiar} /></label>
      <label>Hasta<input type="date" name="hasta" value={valor('hasta')} onChange={cambiar} /></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Bitácora"
      descripcion="Auditoría de acciones e ingresos al sistema. Solo visible para el rol administrador."
      etiquetaFiltros="Filtrar bitácora"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={bitacora.cargando && !bitacora.datos}
      error={bitacora.error}
      resumen={bitacora.datos ? `${bitacora.datos.paginacion.total ?? 0} registros` : null}
      vacio={bitacora.datos?.registros.length === 0 ? { titulo: 'No hay registros con esos filtros', mensaje: 'Prueba ampliando el rango de fechas o quitando algún filtro.' } : null}
      paginacion={bitacora.datos?.registros.length ? {
        pagina: bitacora.datos.paginacion.pagina,
        paginas: bitacora.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      <TablaDatos comparativa etiqueta="Bitácora de auditoría" columnas={COLUMNAS} filas={bitacora.datos?.registros ?? []} />
    </PaginaListado>
  )
}
