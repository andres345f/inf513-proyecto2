import { useId } from 'react'
import { Check } from 'lucide-react'
import './PlantillaFormulario.css'

/**
 * P3 · Plantilla de asistente de creación/edición.
 *
 * - titulo: título general del proceso.
 * - pasos: ['Entrega', 'Pago', 'Revisión'] — lista de pasos.
 * - pasoActual: índice (0-based) del paso visible.
 * - ayuda: texto de contexto del paso actual.
 * - avisoError / avisoExito: mensajes resaltados sobre los campos.
 * - onSubmit: manejador de envío del asistente. Sin él no se envía nada
 *   (por ejemplo, en pasos intermedios que solo navegan).
 * - cargando: mientras es `true`, la sección aria-busy refleja el estado y
 *   los controles de envío quedan deshabilitados para evitar doble envío.
 * - children: campos del paso.
 * - pie: acciones persistentes tipicas (‹ Atrás / Continuar ›). Se renderiza
 *   DENTRO del <form>, por lo que los botones con type="submit" envían con
 *   clic y Enter envían el formulario igual que cualquier botón submit.
 *
 * El último paso es la revisión: la página muestra allí su resumen antes
 * de la acción final de confirmación (destructiva o irreversible).
 */
export default function PlantillaFormulario({
  titulo,
  pasos = [],
  pasoActual = 0,
  ayuda,
  tituloPaso,
  avisoError = null,
  avisoExito = null,
  onSubmit = null,
  cargando = false,
  className = '',
  children,
  pie,
}) {
  const idFormulario = useId()
  const ultimo = pasoActual === pasos.length - 1

  const manejarEnvio = (evento) => {
    // Siempre se interrumpe la navegación nativa del formulario, pero el
    // envío solo se considera «impedido» cuando no hay manejador: con
    // onSubmit la página decide qué hacer (validar, navegar, confirmar).
    evento.preventDefault()
    if (!onSubmit || cargando) return
    onSubmit(evento)
  }

  return (
    <section className={`plantilla-formulario ${className}`.trim()} aria-busy={cargando}>
      <header className="plantilla-formulario__encabezado">
        <h1 className="plantilla-formulario__titulo">{titulo}</h1>
        {pasos.length > 0 && (
          <ol className="plantilla-formulario__pasos" aria-label={`Paso ${pasoActual + 1} de ${pasos.length}: ${pasos[pasoActual]}`}>
            {pasos.map((nombre, indice) => {
              const clases = ['plantilla-formulario__paso',
                indice === pasoActual && 'plantilla-formulario__paso--actual',
                indice < pasoActual && 'plantilla-formulario__paso--hecho'].filter(Boolean).join(' ')
              return (
                <li key={nombre} className={clases} aria-current={indice === pasoActual ? 'step' : undefined}>
                  <span className="plantilla-formulario__paso-numero" aria-hidden="true">
                    {indice < pasoActual ? <Check size={14} /> : indice + 1}
                  </span>
                  <span className="plantilla-formulario__paso-nombre">{nombre}</span>
                </li>
              )
            })}
          </ol>
        )}
      </header>

      <div className="plantilla-formulario__tarjeta">
        <h2 className="plantilla-formulario__paso-titulo">{tituloPaso ?? pasos[pasoActual]}</h2>
        {ayuda && <p className="plantilla-formulario__ayuda">{ayuda}</p>}
        <p className="solo-lector" role="status">Paso {pasoActual + 1} de {pasos.length}</p>

        {avisoError}
        {avisoExito}

        {/* id estable + botón de envío implícito: Enter en cualquier campo
            dispara onSubmit aunque el pie use botones de tipo button. */}
        <form
          id={idFormulario}
          className="plantilla-formulario__cuerpo"
          onSubmit={manejarEnvio}
          aria-busy={cargando}
          noValidate
        >
          <fieldset className="plantilla-formulario__campos" disabled={cargando}>
            {children}
          </fieldset>
          <button type="submit" className="solo-lector" tabIndex={-1} aria-hidden="true" disabled={cargando}>
            Enviar
          </button>

          {pie && (
            <div className="plantilla-formulario__pie no-imprimir">
              {pie}
            </div>
          )}
        </form>
      </div>

      {ultimo && <p className="plantilla-formulario__nota-final">Verifica el resumen antes de confirmar.</p>}
    </section>
  )
}
