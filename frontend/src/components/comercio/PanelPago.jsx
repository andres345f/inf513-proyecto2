import { useEffect, useRef, useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { Banknote, CreditCard, QrCode } from 'lucide-react'
import { comercioApi } from '../../api/comercioApi'
import Alerta from '../ui/Alerta'
import Boton from '../ui/Boton'
import Casilla from '../ui/Casilla'
import FormularioTarjeta from './FormularioTarjeta'
import { TARJETA_VACIA, validarTarjeta } from '../../utils/tarjeta'
import { reintentarConMismaClave } from '../../utils/claveOperacion'
import { cuerpoPago, huellaIntento, intentosPago, tarjetaTrasCargar } from '../../utils/intentoPago'
import { formatearMoneda } from '../../utils/formato'
import './PanelPago.css'

const CONSULTA_QR_MS = 3000

/** Pago del pedido o de la siguiente cuota: QR, tarjeta (guardada o nueva) o efectivo contra entrega. */
export default function PanelPago({ pedido, alActualizar }) {
  const cuota = pedido.plan?.cuotas.find((c) => c.estado === 'PENDIENTE')
  const monto = cuota ? cuota.monto : pedido.saldo
  const [metodo, setMetodo] = useState('TARJETA')
  const [tarjetas, setTarjetas] = useState([])
  const [tarjetaId, setTarjetaId] = useState('')
  const [tarjeta, setTarjeta] = useState(TARJETA_VACIA)
  const [guardar, setGuardar] = useState(false)
  const [errores, setErrores] = useState({})
  const [resultado, setResultado] = useState(null)
  const [confirmacionQr, setConfirmacionQr] = useState(null)
  const [qr, setQr] = useState(null)
  const [enviando, setEnviando] = useState(false)
  // Idempotencia del pago: la clave pertenece al intento (pedido + cuerpo efectivo, ver utils/intentoPago).
  // Se reutiliza mientras su resultado sea desconocido (red, 409 o 5xx) y se renueva si cambia lo que se envía
  // o el servidor ya resolvió el intento. Cambiar campos que no se envían no la toca.
  const enCurso = useRef(false)
  // Si el cliente ya eligió tarjeta, escribió una nueva o envió un intento, la lista que llega tarde no lo cambia.
  const eligioTarjeta = useRef(false)

  useEffect(() => {
    comercioApi.metodosPago().then((r) => {
      const vigentes = r.metodos.filter((m) => !m.vencida)
      setTarjetas(vigentes)
      setTarjetaId((actual) => tarjetaTrasCargar(vigentes, actual, eligioTarjeta.current))
    }).catch(() => setTarjetas([]))
  }, [])

  // Mientras el QR está pendiente, se consulta su estado cada pocos segundos.
  useEffect(() => {
    if (!qr) return undefined
    const intervalo = window.setInterval(async () => {
      const pago = await comercioApi.estadoPago(qr.pagoId).catch(() => null)
      if (pago && pago.estado !== 'PENDIENTE') {
        setQr(null)
        setResultado({ tipo: pago.estado === 'PAGADO' ? 'exito' : 'error', texto: pago.estado === 'PAGADO' ? 'Recibimos tu pago por QR.' : 'El QR fue anulado.' })
        alActualizar(await comercioApi.pedido(pedido.id))
      }
    }, CONSULTA_QR_MS)
    return () => window.clearInterval(intervalo)
  }, [qr, pedido.id, alActualizar])

  const pagar = async () => {
    if (enCurso.current) return
    eligioTarjeta.current = true
    setResultado(null)
    if (metodo === 'TARJETA' && !tarjetaId) {
      const erroresTarjeta = validarTarjeta(tarjeta)
      setErrores(erroresTarjeta)
      if (Object.keys(erroresTarjeta).length > 0) return
    }

    const cuerpo = cuerpoPago({ metodo, cuotaNumero: cuota?.numero ?? null, tarjetaId, tarjeta, guardar })
    const intento = huellaIntento(pedido.id, cuerpo)
    cuerpo.clave_operacion = intentosPago.claveDe(intento)
    await enviarPago(cuerpo, intento)
  }

  const enviarPago = async (cuerpo, intento) => {
    if (enCurso.current) return
    enCurso.current = true
    setEnviando(true)
    try {
      const r = await comercioApi.pagarPedido(pedido.id, cuerpo)
      intentosPago.terminar(intento)
      setConfirmacionQr(null)
      if (r.qr) {
        setQr({ pagoId: r.pago.id, contenido: r.qr.contenido, simulado: r.qr.simulado, monto: r.pago.monto })
      } else {
        setResultado({ tipo: r.resultado === 'RECHAZADO' ? 'error' : 'exito', texto: r.mensaje })
        alActualizar(r.pedido)
      }
    } catch (falla) {
      intentosPago.terminar(intento, falla)
      if (falla.estado === 409 && falla.errores?.anular_qr_pendiente?.length) {
        setConfirmacionQr({ cuerpo, intento, mensaje: falla.mensaje })
        return
      }
      setConfirmacionQr(null)
      const erroresServidor = Object.fromEntries(Object.entries(falla.errores || {}).map(([c, m]) => [c.replace('tarjeta.', ''), m[0]]))
      setErrores(erroresServidor)
      setResultado({ tipo: 'error', texto: reintentarConMismaClave(falla) ? `${falla.mensaje} Si vuelves a intentarlo, no se cobrará dos veces.` : falla.mensaje })
    } finally {
      enCurso.current = false
      setEnviando(false)
    }
  }

  const simularQr = async () => {
    setEnviando(true)
    try {
      const r = await comercioApi.simularPagoQr(qr.pagoId)
      setQr(null)
      setResultado({ tipo: 'exito', texto: r.mensaje })
      alActualizar(r.pedido)
    } catch (falla) {
      setResultado({ tipo: 'error', texto: falla.mensaje })
    } finally {
      setEnviando(false)
    }
  }

  if (confirmacionQr) {
    return (
      <section className="panel-pago" aria-labelledby="titulo-cambio-qr">
        <h2 id="titulo-cambio-qr">Cambiar el medio de pago</h2>
        <Alerta tipo="aviso">{confirmacionQr.mensaje}</Alerta>
        <Boton bloque cargando={enviando} onClick={() => enviarPago(
          { ...confirmacionQr.cuerpo, anular_qr_pendiente: true }, confirmacionQr.intento,
        )}>
          No pagué el QR. Anularlo y continuar con {confirmacionQr.cuerpo.metodo === 'EFECTIVO' ? 'efectivo' : 'tarjeta'}
        </Boton>
        <Boton variante="secundario" disabled={enviando} onClick={() => { setConfirmacionQr(null); setResultado(null) }}>
          Conservar el QR
        </Boton>
      </section>
    )
  }

  if (qr) {
    return (
      <section className="panel-pago" aria-labelledby="titulo-qr">
        <h2 id="titulo-qr">Paga con QR</h2>
        <p>Escanea el código con la aplicación de tu banco. Monto: <strong>{formatearMoneda(qr.monto)}</strong></p>
        <div className="panel-pago__qr"><QRCodeSVG value={qr.contenido} size={200} marginSize={2} title="Código QR de pago" /></div>
        <p className="panel-pago__espera" aria-live="polite">Esperando la confirmación del banco…</p>
        {qr.simulado && (
          <Boton variante="secundario" cargando={enviando} onClick={simularQr}>Simular pago (modo demostración)</Boton>
        )}
        <button type="button" className="panel-pago__cambiar" onClick={() => setQr(null)}>Elegir otro medio de pago</button>
      </section>
    )
  }

  return (
    <section className="panel-pago" aria-labelledby="titulo-pago">
      <h2 id="titulo-pago">{cuota ? `Pagar cuota ${cuota.numero} de ${pedido.plan.num_cuotas}` : 'Pagar pedido'}</h2>
      <p className="panel-pago__monto">{formatearMoneda(monto)}</p>
      {resultado && <Alerta tipo={resultado.tipo}>{resultado.texto}</Alerta>}

      <div className="panel-pago__metodos" role="radiogroup" aria-label="Medio de pago">
        <MetodoOpcion valor="TARJETA" actual={metodo} alElegir={setMetodo} icono={CreditCard} texto="Tarjeta" />
        <MetodoOpcion valor="QR" actual={metodo} alElegir={setMetodo} icono={QrCode} texto="QR" />
        {!pedido.plan && <MetodoOpcion valor="EFECTIVO" actual={metodo} alElegir={setMetodo} icono={Banknote} texto="Efectivo" />}
      </div>

      {metodo === 'TARJETA' && (
        <div className="panel-pago__tarjeta">
          {tarjetas.length > 0 && (
            <div className="panel-pago__guardadas">
              <label htmlFor="tarjeta-guardada">Tarjeta</label>
              <select id="tarjeta-guardada" value={tarjetaId} onChange={(e) => { eligioTarjeta.current = true; setTarjetaId(e.target.value) }}>
                {tarjetas.map((t) => <option key={t.id} value={t.id}>{t.marca} terminada en {t.ultimos4}{t.alias ? ` · ${t.alias}` : ''}</option>)}
                <option value="">Usar otra tarjeta</option>
              </select>
            </div>
          )}
          {!tarjetaId && (
            <>
              <FormularioTarjeta valores={tarjeta} errores={errores} alCambiar={(valores) => { eligioTarjeta.current = true; setTarjeta(valores) }} />
              <Casilla nombre="guardar" checked={guardar} onChange={(e) => setGuardar(e.target.checked)}>
                Guardar esta tarjeta en mis métodos de pago
              </Casilla>
            </>
          )}
        </div>
      )}
      {metodo === 'QR' && <p className="panel-pago__nota">Generaremos un QR para pagar desde la aplicación de tu banco.</p>}
      {metodo === 'EFECTIVO' && <p className="panel-pago__nota">El cobro de {formatearMoneda(monto)} quedará pendiente hasta que FEDER reciba el efectivo.</p>}

      <Boton bloque cargando={enviando} onClick={pagar}>
        {metodo === 'QR' ? 'Generar QR' : metodo === 'EFECTIVO' ? 'Pagar en efectivo al recibir' : `Pagar ${formatearMoneda(monto)}`}
      </Boton>
    </section>
  )
}

function MetodoOpcion({ valor, actual, alElegir, icono: IconoMetodo, texto }) {
  return (
    <button type="button" role="radio" aria-checked={actual === valor}
      className={`panel-pago__metodo${actual === valor ? ' panel-pago__metodo--activo' : ''}`} onClick={() => alElegir(valor)}>
      <IconoMetodo size={20} aria-hidden="true" /> {texto}
    </button>
  )
}
