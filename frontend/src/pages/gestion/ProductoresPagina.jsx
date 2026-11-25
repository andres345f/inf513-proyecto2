import { useCallback, useState } from 'react'

import { useSearchParams } from 'react-router-dom'

import { productoresApi } from '../../api/productoresApi'

import ModalFlotante from '../../components/cliente/ModalFlotante'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { formatearFecha } from '../../utils/formato'

import '../../components/plantillas/gestion.css'

import '../productor/Productor.css'

const ESTADOS = { PENDIENTE: 'Pendiente', APROBADA: 'Aprobada', RECHAZADA: 'Rechazada' }
const TONOS = { PENDIENTE: 'aviso', APROBADA: 'exito', RECHAZADA: 'error' }

const COLUMNAS = [
  { clave: 'nombre', etiqueta: 'Productor', alRender: (p) => <>{p.nombre}<br /><small>{p.correo} · {p.telefono}</small></> },
  { clave: 'codigo', etiqueta: 'Código', alRender: (p) => p.codigo ?? '—' },
  {
    clave: 'comunidad', etiqueta: 'Comunidad',
    alRender: (p) => <>{p.comunidad}{p.comunidad_nueva && <> <Insignia tono="aviso">Comunidad nueva</Insignia></>}<br /><small>{p.ubicacion}</small></>,
  },
  {
    clave: 'estado', etiqueta: 'Solicitud',
    alRender: (p) => <><Insignia tono={TONOS[p.estado]}>{ESTADOS[p.estado]}</Insignia>{p.motivo_rechazo && <><br /><small>{p.motivo_rechazo}</small></>}</>,
  },
  { clave: 'fecha_solicitud', etiqueta: 'Fecha', alRender: (p) => formatearFecha(p.fecha_solicitud) },
]

/**
 * Solicitudes de afiliación de productores y cambios de comunidad. Las decisiones
 * (aprobar o rechazar con motivo) se toman dentro del modal «Revisar», nunca en la fila.
 */
export default function ProductoresPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => productoresApi.productores(Object.fromEntries(parametros)), [clave])
  const comunidades = useCargaApi(() => productoresApi.comunidadesDisponibles(), [])
  const cambios = useCargaApi(() => productoresApi.cambiosComunidad(), [])
  // Modal abierto: { tipo: 'afiliacion' | 'cambio', registro }
  const [revisado, setRevisado] = useState(null)
  const [modo, setModo] = useState(null) // null | 'rechazar'
  const [motivo, setMotivo] = useState('')
  const [ocupado, setOcupado] = useState(false)
  const [mensaje, setMensaje] = useState(null)
  useMensajeTemporal(mensaje, setMensaje)

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const cerrarModal = () => {
    setRevisado(null)
    setModo(null)
    setMotivo('')
  }

  const ejecutar = async (llamada) => {
    setOcupado(true)
    setMensaje(null)
    try {
      setMensaje({ tipo: 'exito', texto: (await llamada()).message })
      lista.recargar()
      cambios.recargar()
      cerrarModal()
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: Object.values(falla.errores || {})[0]?.[0] || falla.mensaje })
    } finally {
      setOcupado(false)
    }
  }

  const valor = (c) => parametros.get(c) ?? ''

  const rechazar = (e) => {
    e.preventDefault()
    if (!revisado) return
    if (motivo.trim().length < 5) {
      setMensaje({ tipo: 'error', texto: 'El motivo debe tener al menos 5 caracteres.' })
      return
    }
    const llamada = revisado.tipo === 'afiliacion'
      ? () => productoresApi.rechazar(revisado.registro.id, motivo.trim())
      : () => productoresApi.resolverCambioComunidad(revisado.registro.id, false, motivo.trim())
    ejecutar(llamada)
  }

  const aprobar = () => {
    if (!revisado) return
    const llamada = revisado.tipo === 'afiliacion'
      ? () => productoresApi.aprobar(revisado.registro.id)
      : () => productoresApi.resolverCambioComunidad(revisado.registro.id, true)
    ejecutar(llamada)
  }

  const pendientesCambio = (cambios.datos?.solicitudes ?? []).filter((s) => s.estado === 'PENDIENTE')
  const cambioRevisado = revisado?.tipo === 'cambio'
    ? pendientesCambio.find((s) => s.id === revisado.registro.id)
    : null

  const filtros = (
    <>
      <label>Buscar<input defaultValue={valor('q')} maxLength={80} placeholder="Nombre, código o correo"
        onBlur={(e) => e.target.value !== valor('q') && filtrar({ q: e.target.value })}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Solicitud<select value={valor('estado')} onChange={(e) => filtrar({ estado: e.target.value })}>
        <option value="">Todas</option>
        {Object.entries(ESTADOS).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Comunidad<select value={valor('comunidad')} onChange={(e) => filtrar({ comunidad: e.target.value })}>
        <option value="">Todas</option>
        {comunidades.datos?.comunidades.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
      </select></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Productores"
      descripcion="Abre «Revisar solicitud» para ver los datos completos y aprobar o rechazar con motivo."
      etiquetaFiltros="Filtrar productores"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} solicitudes` : null}
      vacio={lista.datos?.productores.length === 0 ? { titulo: 'No hay solicitudes con esos filtros', mensaje: 'Prueba quitando algún filtro de estado o comunidad.' } : null}
      paginacion={lista.datos?.productores.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      {mensaje && !revisado && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      {cambios.datos?.solicitudes?.some((s) => s.estado === 'PENDIENTE') && (
        <details className="productor__panel">
          <summary>Cambios de comunidad pendientes</summary>
          {pendientesCambio.map((s) => (
            <div key={s.id} className="productor__fila-acciones">
              <span><strong>{s.correo}</strong><br /><small>{s.comunidad_anterior} → {s.comunidad_nueva}, {s.municipio_nuevo}</small></span>
              <Boton variante="secundario" onClick={() => { setRevisado({ tipo: 'cambio', registro: s }); setModo(null); setMotivo('') }}>Revisar cambio</Boton>
            </div>
          ))}
        </details>
      )}

      <TablaDatos
        columnas={[...COLUMNAS, {
          clave: 'acciones', etiqueta: 'Acciones',
          alRender: (p) => p.estado === 'PENDIENTE'
            ? <Boton variante="secundario" onClick={() => { setRevisado({ tipo: 'afiliacion', registro: p }); setModo(null); setMotivo('') }}>Revisar solicitud</Boton>
            : '—',
        }]}
        filas={lista.datos?.productores ?? []}
      />

      {revisado && (
        <ModalFlotante titulo={revisado.tipo === 'afiliacion' ? `Revisar solicitud · ${revisado.registro.nombre}` : 'Revisar cambio de comunidad'} alCerrar={cerrarModal} ancho="angosto">
          {revisado.tipo === 'afiliacion' ? (
            <dl className="gestion__detalle-modal">
              <dt>Productor</dt><dd>{revisado.registro.nombre}</dd>
              <dt>Correo</dt><dd>{revisado.registro.correo}</dd>
              <dt>Teléfono</dt><dd>{revisado.registro.telefono}</dd>
              <dt>Código</dt><dd>{revisado.registro.codigo ?? '—'}</dd>
              <dt>Comunidad</dt><dd>{revisado.registro.comunidad}</dd>
              {revisado.registro.comunidad_nueva && <><dt>Comunidad nueva</dt><dd>{revisado.registro.comunidad_nueva}</dd></>}
              <dt>Ubicación</dt><dd>{revisado.registro.ubicacion}</dd>
              <dt>Fecha</dt><dd>{formatearFecha(revisado.registro.fecha_solicitud)}</dd>
            </dl>
          ) : (
            <dl className="gestion__detalle-modal">
              <dt>Correo</dt><dd>{revisado.registro.correo}</dd>
              <dt>Comunidad actual</dt><dd>{revisado.registro.comunidad_anterior}</dd>
              <dt>Comunidad nueva</dt><dd>{revisado.registro.comunidad_nueva}</dd>
              <dt>Municipio</dt><dd>{revisado.registro.municipio_nuevo}</dd>
            </dl>
          )}
          {mensaje && revisado && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
          {modo === 'rechazar' ? (
            <form className="gestion__cancelar" noValidate onSubmit={rechazar}>
              <label htmlFor="motivo-rechazo-productor">Motivo del rechazo</label>
              <input id="motivo-rechazo-productor" value={motivo} maxLength={255} onChange={(e) => setMotivo(e.target.value)} />
              <div className="gestion__botones">
                <Boton tipo="submit" variante="peligro" cargando={ocupado}>Confirmar rechazo</Boton>
                <Boton variante="fantasma" onClick={() => { setModo(null); setMotivo('') }}>Volver</Boton>
              </div>
            </form>
          ) : (
            <div className="gestion__botones">
              <Boton cargando={ocupado} onClick={aprobar}>
                {revisado.tipo === 'afiliacion' ? 'Confirmar aprobación' : 'Confirmar aprobación del cambio'}
              </Boton>
              <Boton variante="peligro" onClick={() => { setModo('rechazar'); setMotivo('') }}>Rechazar…</Boton>
            </div>
          )}
          {cambioRevisado && <p className="gestion__resumen">El productor pasará a aparecer en la comunidad nueva del listado.</p>}
        </ModalFlotante>
      )}
    </PaginaListado>
  )
}
