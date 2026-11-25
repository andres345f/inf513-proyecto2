import { useCallback, useState } from 'react'

import { Link, useSearchParams } from 'react-router-dom'

import { gestionApi } from '../../api/gestionApi'

import ModalFlotante from '../../components/cliente/ModalFlotante'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'

import { ESTADOS_PAGO, METODOS_PAGO, tonoEstado } from '../../utils/etiquetas'

import { formatearFechaHora, formatearMoneda } from '../../utils/formato'

import '../../components/plantillas/gestion.css'

/**
 * Pagos de todos los clientes. Los pendientes se resuelven a mano: un QR verificado en el banco
 * o un efectivo cobrado antes de la entrega se confirman; un QR que no llegó se marca como no recibido.
 * La decisión vive en un modal («Ver y gestionar»): la fila nunca ejecuta acciones.
 */

const COLUMNAS = [
  { clave: 'fecha', etiqueta: 'Fecha', alRender: (p) => formatearFechaHora(p.fecha) },
  { clave: 'pedido', etiqueta: 'Pedido', alRender: (p) => <Link to={`/gestion/pedidos/${p.pedido.id}`}>{p.pedido.codigo}</Link> },
  { clave: 'cliente', etiqueta: 'Cliente' },
  { clave: 'metodo', etiqueta: 'Método', alRender: (p) => METODOS_PAGO[p.metodo] },
  { clave: 'monto', etiqueta: 'Monto', alRender: (p) => formatearMoneda(p.monto) },
  { clave: 'estado', etiqueta: 'Estado', alRender: (p) => <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PAGO[p.estado]}</Insignia> },
  { clave: 'referencia', etiqueta: 'Referencia', alRender: (p) => <small>{p.referencia}</small> },
]

export default function GestionPagosPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => gestionApi.pagos(Object.fromEntries(parametros)), [clave])
  const [gestionado, setGestionado] = useState(null) // pago abierto en el modal
  const [rechazando, setRechazando] = useState(false)
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

  const cambiar = (e) => filtrar({ [e.target.name]: e.target.value })
  const valor = (c) => parametros.get(c) || ''

  const cerrarModal = () => {
    setGestionado(null)
    setRechazando(false)
    setMotivo('')
  }

  const ejecutar = async (llamada) => {
    setOcupado(true)
    setMensaje(null)
    try {
      setMensaje({ tipo: 'exito', texto: (await llamada()).mensaje })
      lista.recargar()
      cerrarModal()
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: Object.values(falla.errores || {})[0]?.[0] || falla.mensaje })
    } finally {
      setOcupado(false)
    }
  }

  const rechazar = (e) => {
    e.preventDefault()
    if (!gestionado) return
    if (motivo.trim().length < 3) {
      setMensaje({ tipo: 'error', texto: 'El motivo debe tener al menos 3 caracteres.' })
      return
    }
    ejecutar(() => gestionApi.rechazarPago(gestionado.id, motivo.trim()))
  }

  const filtros = (
    <>
      <label>Buscar<input type="search" name="q" defaultValue={valor('q')} maxLength={60} placeholder="Pedido, cliente o referencia"
        onKeyDown={(e) => e.key === 'Enter' && filtrar({ q: e.currentTarget.value })} onBlur={(e) => filtrar({ q: e.target.value })} /></label>
      <label>Estado<select name="estado" value={valor('estado')} onChange={cambiar}>
        <option value="">Todos</option>
        {Object.entries(ESTADOS_PAGO).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Método<select name="metodo" value={valor('metodo')} onChange={cambiar}>
        <option value="">Todos</option>
        {Object.entries(METODOS_PAGO).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Desde<input type="date" name="desde" value={valor('desde')} onChange={cambiar} /></label>
      <label>Hasta<input type="date" name="hasta" value={valor('hasta')} onChange={cambiar} /></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Pagos"
      descripcion="Abre «Ver y gestionar» para confirmar un cobro verificado o registrar el motivo si un pago por QR o tarjeta quedó pendiente y no se recibió."
      etiquetaFiltros="Filtrar pagos"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} pagos` : null}
      vacio={lista.datos?.pagos.length === 0 ? { titulo: 'No hay pagos con esos filtros', mensaje: 'Prueba ampliando el rango de fechas o quitando algún filtro.' } : null}
      paginacion={lista.datos?.pagos.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      {mensaje && !gestionado && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      <TablaDatos
        columnas={[...COLUMNAS, {
          clave: 'acciones', etiqueta: 'Acciones',
          alRender: (p) => p.acciones.confirmar
            ? <Boton variante="secundario" onClick={() => { setGestionado(p); setRechazando(false); setMotivo('') }}>Ver y gestionar</Boton>
            : '—',
        }]}
        filas={lista.datos?.pagos ?? []}
      />

      {gestionado && (
        <ModalFlotante titulo={`Gestionar pago · ${gestionado.pedido.codigo}`} alCerrar={cerrarModal} ancho="angosto">
          <dl className="gestion__detalle-modal">
            <dt>Pedido</dt><dd><Link to={`/gestion/pedidos/${gestionado.pedido.id}`}>{gestionado.pedido.codigo}</Link></dd>
            <dt>Cliente</dt><dd>{gestionado.cliente}</dd>
            <dt>Método</dt><dd>{METODOS_PAGO[gestionado.metodo]}</dd>
            <dt>Monto</dt><dd>{formatearMoneda(gestionado.monto)}</dd>
            <dt>Estado</dt><dd><Insignia tono={tonoEstado(gestionado.estado)}>{ESTADOS_PAGO[gestionado.estado]}</Insignia></dd>
            {gestionado.referencia && <><dt>Referencia</dt><dd>{gestionado.referencia}</dd></>}
            <dt>Fecha</dt><dd>{formatearFechaHora(gestionado.fecha)}</dd>
          </dl>
          {mensaje && gestionado && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
          {rechazando ? (
            <form className="gestion__cancelar" noValidate onSubmit={rechazar}>
              <label htmlFor="motivo-rechazo-pago">Motivo: por qué no se recibió</label>
              <input id="motivo-rechazo-pago" value={motivo} maxLength={200} onChange={(e) => setMotivo(e.target.value)} />
              <div className="gestion__botones">
                <Boton tipo="submit" variante="peligro" cargando={ocupado}>Confirmar: marcar no recibido</Boton>
                <Boton variante="fantasma" onClick={() => { setRechazando(false); setMotivo('') }}>Volver</Boton>
              </div>
            </form>
          ) : (
            <div className="gestion__botones">
              <Boton cargando={ocupado} onClick={() => ejecutar(() => gestionApi.confirmarPago(gestionado.id))}>
                {gestionado.metodo === 'EFECTIVO' ? 'Confirmar: registrar cobro' : 'Confirmar pago recibido'}
              </Boton>
              {gestionado.acciones.rechazar && (
                <Boton variante="peligro" onClick={() => setRechazando(true)}>Marcar no recibido…</Boton>
              )}
            </div>
          )}
        </ModalFlotante>
      )}
    </PaginaListado>
  )
}
