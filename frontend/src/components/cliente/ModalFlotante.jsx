import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import Boton from '../ui/Boton'
import './ModalFlotante.css'

/**
 * Diálogo accesible compartido por los flujos del
 * cliente (nueva tarjeta, devolución sobre un pedido entregado).
 *
 * - role="dialog" aria-modal, con título enlazado por aria-labelledby.
 * - Escape o clic en el fondo lo cierran; el foco vuelve al disparador.
 * - Tab queda atrapado dentro del modal (wrap en ambos sentidos).
 * - El contenido de la página queda detrás del velo, sin interacción.
 */
// Anchos máximos disponibles (clases en ModalFlotante.css; sin estilos en línea).
const ANCHOS = ['angosto', 'mediano', 'amplio']

export default function ModalFlotante({ titulo, alCerrar, children, ancho = 'amplio' }) {
  const id = useId()
  const refCuadro = useRef(null)

  useEffect(() => {
    // Guarda quién abrió el modal (mientras el foco aún está en él) y pásale el foco al cerrar.
    const disparador = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const app = document.getElementById('root')
    const estabaInerte = app?.inert
    const overflowAnterior = document.body.style.overflow
    if (app) app.inert = true
    document.body.style.overflow = 'hidden'
    const cuadro = refCuadro.current
    const focoInicial = cuadro?.querySelector('[data-foco-inicial]') ?? cuadro
    focoInicial?.focus()
    return () => {
      if (app) app.inert = estabaInerte
      document.body.style.overflow = overflowAnterior
      if (disparador?.isConnected) disparador.focus()
    }
  }, [id])

  const controles = () => refCuadro.current
    ? Array.from(refCuadro.current.querySelectorAll('button, a[href], input, select, textarea')).filter(
        (c) => !c.disabled && c.offsetParent !== null,
      )
    : []

  const alTeclado = (evento) => {
    if (evento.key === 'Escape') { evento.preventDefault(); alCerrar() }
    if (evento.key !== 'Tab') return
    const lista = controles()
    if (lista.length === 0) return
    const indice = lista.indexOf(document.activeElement)
    if (indice === -1) { evento.preventDefault(); lista[0].focus(); return }
    const enElBorde = evento.shiftKey ? indice === 0 : indice === lista.length - 1
    if (!enElBorde) return
    evento.preventDefault()
    lista[evento.shiftKey ? lista.length - 1 : 0].focus()
  }

  return createPortal(
    <div className="modal-flotante" onKeyDown={alTeclado}>
      <div className="modal-flotante__fondo" onClick={alCerrar} aria-hidden="true" />
      <section
        id={id}
        ref={refCuadro}
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${id}-titulo`}
        className={`modal-flotante__cuadro modal-flotante__cuadro--${ANCHOS.includes(ancho) ? ancho : 'amplio'}`}
        tabIndex={-1}
      >
        <header className="modal-flotante__cabecera">
          <h2 id={`${id}-titulo`} className="modal-flotante__titulo">{titulo}</h2>
          <Boton variante="fantasma" onClick={alCerrar} aria-label="Cerrar ventana">
            <X size={18} aria-hidden="true" />
          </Boton>
        </header>
        <div className="modal-flotante__cuerpo">{children}</div>
      </section>
    </div>,
    document.body,
  )
}
