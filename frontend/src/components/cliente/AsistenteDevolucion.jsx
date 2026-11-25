import { useState } from 'react'
import { devolucionesApi } from '../../api/devolucionesApi'
import Alerta from '../ui/Alerta'
import CampoTexto from '../ui/CampoTexto'
import Cargando from '../ui/Cargando'
import PlantillaFormulario from '../plantillas/PlantillaFormulario'
import Boton from '../ui/Boton'
import { useCargaApi } from '../../hooks/useCargaApi'
import { cantidadConUnidad } from '../../utils/etiquetas'
import ModalFlotante from './ModalFlotante'
import './AsistenteDevolucion.css'

const VACIO = { despacho_id: '', cantidad: '1', motivo: '' }

/**
 * Asistente de devolución (Fase B), compartido:
 * - desde /mis-devoluciones («Solicitar una devolución»);
 * - desde el detalle de un pedido entregado (con sus propios productos preseleccionados).
 *
 * Paso 1: producto y cantidad. Paso 2: motivo y revisión antes de enviar.
 * POST /mis-devoluciones; el backend valida propiedad y máximos otra vez.
 */
export default function AsistenteDevolucion({ alCerrar, alSolicitada, pedidoFiltro = '' }) {
  const carga = useCargaApi(() => devolucionesApi.mias(), [])
  const [paso, setPaso] = useState(0)
  const [formulario, setFormulario] = useState({ ...VACIO, cantidad: '1' })
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState(null)
  const [enviando, setEnviando] = useState(false)

  const opciones = (carga.datos?.devolvibles || [])
    .filter((d) => !pedidoFiltro || d.pedido === pedidoFiltro)
  const elegido = opciones.find((d) => String(d.despacho_id) === formulario.despacho_id)

  const cambiar = (e) => {
    setFormulario((f) => ({ ...f, [e.target.name]: e.target.value }))
    setErrores((er) => ({ ...er, [e.target.name]: null }))
  }

  const validarPasoActual = () => {
    const nuevos = {}
    if (paso === 0) {
      if (!elegido) nuevos.despacho_id = 'Elige el producto que quieres devolver.'
      const cantidad = Number(formulario.cantidad)
      if (!(cantidad > 0)) nuevos.cantidad = 'La cantidad debe ser mayor a cero.'
      else if (elegido && cantidad > elegido.maximo) nuevos.cantidad = `Puedes devolver como máximo ${cantidadConUnidad(elegido.maximo, elegido.unidad)}.`
    } else if (formulario.motivo.trim().length < 5) {
      nuevos.motivo = 'Describe el problema con al menos 5 caracteres.'
    }
    return nuevos
  }

  const enviarSolicitud = async () => {
    setEnviando(true)
    try {
      const respuesta = await devolucionesApi.solicitar({
        despacho_id: Number(formulario.despacho_id),
        cantidad: Number(formulario.cantidad),
        motivo: formulario.motivo.trim(),
      })
      alSolicitada?.(respuesta.mensaje || 'Recibimos tu solicitud de devolución.')
      alCerrar()
    } catch (falla) {
      setErrores(Object.fromEntries(Object.entries(falla.errores || {}).map(([c, m]) => [c, m[0]])))
      setErrorGeneral(falla.mensaje)
    } finally {
      setEnviando(false)
    }
  }

  const alEnviar = () => {
    const nuevos = validarPasoActual()
    setErrores(nuevos)
    setErrorGeneral(Object.keys(nuevos).length > 0 ? 'Revisa los datos marcados.' : null)
    if (Object.keys(nuevos).length > 0) return
    setPaso((p) => (p === 0 ? 1 : p))
    if (paso === 1) enviarSolicitud()
  }

  return (
    <ModalFlotante titulo="Solicitar una devolución" alCerrar={alCerrar}>
      {carga.cargando && <Cargando texto="Buscando tus productos entregados…" />}
      {carga.error && <Alerta tipo="error">{carga.error}</Alerta>}

      {carga.datos && opciones.length === 0 && (
        <>
          <Alerta tipo="aviso">
            {pedidoFiltro
              ? `No hay productos disponibles para devolver del pedido ${pedidoFiltro}.`
              : 'No hay productos disponibles para devolver todavía: recién llegado tu pedido puedes solicitarla desde aquí.'}
          </Alerta>
          <div className="asistente-devolucion__pie">
            <Boton variante="secundario" onClick={alCerrar}>Cerrar</Boton>
          </div>
        </>
      )}

      {carga.datos && opciones.length > 0 && (
        <PlantillaFormulario
          titulo="Nueva devolución"
          pasos={['Producto', 'Motivo']}
          pasoActual={paso}
          ayuda={paso === 0
            ? 'Elige el producto del pedido entregado y la cantidad a devolver.'
            : 'Cuenta qué pasó con el producto; se envía para revisión del Personal de FEDER.'}
          onSubmit={alEnviar}
          cargando={enviando}
          avisoError={errorGeneral ? <Alerta tipo="error">{errorGeneral}</Alerta> : null}
          pie={
            <>
              {paso === 1 && <Boton variante="secundario" onClick={() => setPaso(0)} disabled={enviando}>‹ Volver al producto</Boton>}
              {paso === 0 && <Boton tipo="submit">Continuar ›</Boton>}
              {paso === 1 && <Boton tipo="submit" cargando={enviando}>Enviar solicitud</Boton>}
            </>
          }
        >
          {paso === 0 && (
            <>
              <div className="asistente-devolucion__selector">
                <label htmlFor="devolucion-producto">Producto de un pedido entregado <span aria-hidden="true">*</span></label>
                <select id="devolucion-producto" name="despacho_id" value={formulario.despacho_id} onChange={cambiar}
                  aria-invalid={Boolean(errores.despacho_id)} aria-required="true">
                  <option value="">Elige un producto…</option>
                  {opciones.map((d) => (
                    <option key={d.despacho_id} value={d.despacho_id}>{d.pedido} · {d.producto} (hasta {cantidadConUnidad(d.maximo, d.unidad)})</option>
                  ))}
                </select>
                {errores.despacho_id && <p className="asistente-devolucion__error" role="alert">{errores.despacho_id}</p>}
              </div>
              <CampoTexto etiqueta="Cantidad" nombre="cantidad" tipo="number" min="0" step="any" obligatorio
                value={formulario.cantidad} onChange={cambiar} error={errores.cantidad}
                ayuda={elegido ? `Máximo a devolver: ${cantidadConUnidad(elegido.maximo, elegido.unidad)}` : undefined} />
            </>
          )}

          {paso === 1 && (
            <>
              <dl className="asistente-devolucion__resumen">
                <dt>Producto</dt><dd>{elegido ? elegido.producto : '—'}</dd>
                <dt>Pedido</dt><dd>{elegido ? elegido.pedido : '—'}</dd>
                <dt>Cantidad</dt><dd>{elegido ? cantidadConUnidad(Number(formulario.cantidad), elegido.unidad) : '—'}</dd>
              </dl>
              <CampoTexto etiqueta="¿Qué pasó con el producto?" nombre="motivo" maxLength={255} obligatorio
                value={formulario.motivo} onChange={cambiar} error={errores.motivo}
                ayuda="Ejemplo: el producto llegó con el empaque dañado." />
            </>
          )}
        </PlantillaFormulario>
      )}
    </ModalFlotante>
  )
}
